<?php

declare(strict_types=1);

use FluentForm\App\Modules\Form\Form as FormCreator;
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Validator\ValidationException;
use ZirkelDesign\CapCaptcha\Integration\FluentForms;
use ZirkelDesign\CapCaptcha\Settings;

/**
 * @param  array<int, array<string, mixed>>  $fields
 */
function capFluentSetFields(int $formId, array $fields): void
{
    global $wpdb;
    $wpdb->update($wpdb->prefix.'fluentform_forms', [
        'form_fields' => wp_json_encode([
            'fields' => $fields,
            'submitButton' => [
                'element' => 'submit_button',
                'attributes' => ['type' => 'submit', 'class' => 'ff-btn-submit'],
                'settings' => ['button_ui' => ['type' => 'default', 'text' => 'Submit']],
            ],
        ]),
    ], ['id' => $formId]);
}

/**
 * A pre_http_request callback that answers Cap's /siteverify with $body, or
 * with a transport error when $body is null.
 *
 * @param  array<string, mixed>|null  $body
 */
function capFluentCapResponse(?array $body): Closure
{
    return static function ($preempt, $args, $url) use ($body) {
        if ($preempt !== false || ! str_contains((string) $url, '/siteverify')) {
            return $preempt;
        }

        if ($body === null) {
            return new WP_Error('http_request_failed', 'Cap is unreachable');
        }

        return [
            'headers' => [],
            'body' => wp_json_encode($body),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    };
}

function capFluentSubmissionCount(int $formId): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d",
        $formId,
    ));
}

beforeEach(function (): void {
    if (! defined('FLUENTFORM')) {
        $this->markTestSkipped('Fluent Forms is not active in the WordPress fixture.');
    }

    $created = (new FormCreator(wpFluentForm()))->store(false);
    $this->formId = (int) $created['formId'];
    $components = apply_filters('fluentform/editor_components', [], $this->formId);
    $fields = array_values(array_filter(
        $components['advanced'],
        static fn (array $component): bool => ($component['element'] ?? '') === FluentForms::ELEMENT,
    ));
    expect($fields)->toHaveCount(1);
    $this->capField = $fields[0];
    capFluentSetFields($this->formId, $fields);

    $this->capStub = capFluentCapResponse(['success' => true]);
    add_filter('pre_http_request', $this->capStub, 10, 3);
});

afterEach(function (): void {
    if (empty($this->formId)) {
        return;
    }

    // The fixture is shared with the Gravity Forms suite: a stub left behind
    // would answer its /siteverify calls too.
    remove_filter('pre_http_request', $this->capStub, 10);

    global $wpdb;
    foreach (['fluentform_submission_meta', 'fluentform_entry_details', 'fluentform_submissions', 'fluentform_form_meta', 'fluentform_forms'] as $table) {
        $wpdb->delete($wpdb->prefix.$table, [$table === 'fluentform_forms' ? 'id' : 'form_id' => $this->formId]);
    }
});

it('renders the field in a real Fluent Forms form', function (): void {
    $html = do_shortcode('[fluentform id="'.$this->formId.'"]');

    expect($html)->toContain('<cap-widget')
        ->and($html)->toContain('cap-captcha--fluent')
        ->and(wp_script_is('cap-captcha-fluent-forms', 'enqueued'))->toBeTrue();
});

it('turns the real submit button into the floating trigger', function (): void {
    // Settings reads the option once per request, so drop what earlier tests loaded.
    $forget = static fn () => (new ReflectionProperty(Settings::class, 'cache'))->setValue(Settings::get_instance(), null);
    $floating = static fn ($settings): array => ['display_mode' => Settings::MODE_FLOATING] + (array) $settings;
    add_filter('option_'.Settings::OPTION_KEY, $floating);
    $forget();
    try {
        $html = do_shortcode('[fluentform id="'.$this->formId.'"]');
    } finally {
        remove_filter('option_'.Settings::OPTION_KEY, $floating);
        $forget();
    }

    expect(preg_match('/<cap-widget id="([^"]+)"/', $html, $widget))->toBe(1)
        ->and(substr_count($html, 'data-cap-floating='))->toBe(1)
        ->and($html)->toMatch('/<button data-cap-floating="#'.preg_quote($widget[1], '/').'"[^>]*type="submit"/')
        ->and($html)->not->toContain('cap-captcha-floating-trigger');
});

it('rejects a real submission without a token', function (): void {
    try {
        (new SubmissionHandlerService)->handleSubmission([], $this->formId);
        $this->fail('Fluent Forms accepted the submission without a CAPTCHA token.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['errors'])->toHaveKey('cap-token');
    }
});

it('does not require a token on a form without the CAPTCHA field', function (): void {
    global $wpdb;
    $wpdb->update($wpdb->prefix.'fluentform_forms', [
        'form_fields' => wp_json_encode(['fields' => []]),
    ], ['id' => $this->formId]);

    (new SubmissionHandlerService)->handleSubmission([], $this->formId);
    expect(capFluentSubmissionCount($this->formId))->toBe(1);
});

it('accepts a verified token without saving it in the entry', function (): void {
    (new SubmissionHandlerService)->handleSubmission(['cap-token' => 'solved-token'], $this->formId);

    global $wpdb;
    $entry = $wpdb->get_row($wpdb->prepare(
        "SELECT response FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d ORDER BY id DESC LIMIT 1",
        $this->formId,
    ));
    expect($entry)->not->toBeNull()
        ->and(json_decode($entry->response, true))->not->toHaveKey('cap-token');
});

it('rejects a token the Cap server refuses', function (): void {
    $reject = capFluentCapResponse(['success' => false]);
    add_filter('pre_http_request', $reject, 5, 3);
    try {
        (new SubmissionHandlerService)->handleSubmission(['cap-token' => 'rejected-token'], $this->formId);
        $this->fail('Fluent Forms accepted a rejected CAPTCHA token.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['errors'])->toHaveKey('cap-token');
    } finally {
        remove_filter('pre_http_request', $reject, 5);
    }
});

it('still requires a token when the CAPTCHA sits in a conditionally hidden container', function (): void {
    // Fluent Forms drops fields of a container whose condition is not met from
    // the list it hands to validation, and the condition is evaluated against
    // the submitted data. Leaving the controlling value out must not switch
    // the CAPTCHA off.
    capFluentSetFields($this->formId, [[
        'element' => 'container',
        'attributes' => [],
        'settings' => ['conditional_logics' => [
            'status' => true,
            'type' => 'any',
            'conditions' => [['field' => 'reveal', 'operator' => '=', 'value' => 'yes']],
        ]],
        'columns' => [['fields' => [$this->capField]]],
    ]]);

    try {
        (new SubmissionHandlerService)->handleSubmission(['reveal' => 'no'], $this->formId);
        $this->fail('Hiding the CAPTCHA container through the submitted data skipped verification.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['errors'])->toHaveKey('cap-token');
    }

    expect(capFluentSubmissionCount($this->formId))->toBe(0);
});

it('registers every hook and offers the toggle when Fluent Forms is active', function (): void {
    foreach ([
        'fluentform/editor_components',
        'fluentform/editor_element_settings_placement',
        'fluentform/form_input_types',
        'fluentform/render_item_'.FluentForms::ELEMENT,
        'fluentform/validation_errors',
        'fluentform/insert_response_data',
    ] as $hook) {
        expect(has_filter($hook))->toBeTrue("{$hook} is not hooked");
    }

    $placements = apply_filters('fluentform/editor_element_settings_placement', [], (object) ['id' => $this->formId]);
    expect($placements)->toHaveKey(FluentForms::ELEMENT);

    // submit_button() lives in the admin template API, which a front-end boot does not load.
    require_once ABSPATH.'wp-admin/includes/template.php';
    wp_set_current_user(1);
    ob_start();
    Settings::get_instance()->renderPage();
    $page = (string) ob_get_clean();
    expect($page)->toContain('[integrations][fluent_forms]')
        ->and($page)->toContain('[fail_open_overrides][fluent_forms]');
});

it('does not block a form with the field while the surface is switched off', function (): void {
    add_filter('cap_captcha_protect_fluent_forms', '__return_false');
    try {
        expect(do_shortcode('[fluentform id="'.$this->formId.'"]'))->not->toContain('cap-captcha--fluent');
        (new SubmissionHandlerService)->handleSubmission([], $this->formId);
    } finally {
        remove_filter('cap_captcha_protect_fluent_forms', '__return_false');
    }

    expect(capFluentSubmissionCount($this->formId))->toBe(1);
});

it('lets a submission through an unreachable Cap server only when fail-open is on', function (): void {
    $unreachable = capFluentCapResponse(null);
    add_filter('pre_http_request', $unreachable, 5, 3);
    try {
        try {
            (new SubmissionHandlerService)->handleSubmission(['cap-token' => 'closed-token'], $this->formId);
            $this->fail('Fluent Forms accepted a submission Cap could not verify while fail-open is off.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['errors'])->toHaveKey('cap-token');
        }

        add_filter('cap_captcha_fail_open', '__return_true');
        (new SubmissionHandlerService)->handleSubmission(['cap-token' => 'open-token'], $this->formId);
    } finally {
        remove_filter('cap_captcha_fail_open', '__return_true');
        remove_filter('pre_http_request', $unreachable, 5);
    }

    expect(capFluentSubmissionCount($this->formId))->toBe(1);
});
