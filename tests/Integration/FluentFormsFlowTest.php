<?php

declare(strict_types=1);

use FluentForm\App\Modules\Form\Form as FormCreator;
use FluentForm\App\Services\Form\SubmissionHandlerService;
use FluentForm\Framework\Validator\ValidationException;
use ZirkelDesign\CapCaptcha\Integration\FluentForms;

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
    ], ['id' => $this->formId]);

    add_filter('pre_http_request', static function ($preempt, $args, $url) {
        if ($preempt !== false || ! str_contains((string) $url, '/siteverify')) {
            return $preempt;
        }

        return [
            'headers' => [],
            'body' => wp_json_encode(['success' => true]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }, 10, 3);
});

afterEach(function (): void {
    if (empty($this->formId)) {
        return;
    }

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
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d",
        $this->formId,
    ));
    expect((int) $count)->toBe(1);
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
    $reject = static function ($preempt, $args, $url) {
        if (! str_contains((string) $url, '/siteverify')) {
            return $preempt;
        }

        return [
            'headers' => [],
            'body' => wp_json_encode(['success' => false]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    };
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