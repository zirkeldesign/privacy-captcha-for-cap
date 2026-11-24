<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\WpForms;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/** Stands in for WPForms' process object: the errors by form id that its process() collects. */
final class CapWpFormsProcessStub
{
    /** @var array<int, array<string, mixed>> */
    public array $errors = [];
}

/** Stands in for WPForms Pro's entry meta handler, which Lite does not have. */
final class CapWpFormsEntryMetaStub
{
    /** @var array<int, array<string, mixed>> */
    public array $added = [];

    /** @param array<string, mixed> $data */
    public function add(array $data, string $type = ''): int
    {
        $this->added[] = $data;

        return count($this->added);
    }
}

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    cap_reset_remote_stub();
    $_POST = [];
    $GLOBALS['__cap_modules'] = [];
    $GLOBALS['__cap_scripts'] = [];
    $GLOBALS['__cap_actions'] = [];
    $GLOBALS['__cap_wpforms_amp'] = false;
    $GLOBALS['__cap_wpforms_pro'] = false;
    $GLOBALS['__cap_wpforms_panel_fields'] = [];
    $GLOBALS['__cap_wpforms_objects'] = ['process' => new CapWpFormsProcessStub];
});

/** @param array<string, mixed> $extra */
function capWpForms(
    bool $enabled = true,
    bool $protectAll = true,
    bool $failOpen = false,
    bool $configured = true,
    string $mode = Settings::MODE_INLINE,
    array $extra = [],
): WpForms {
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => $configured ? 'https://cap.example.com/' : '',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'display_mode' => $mode,
        'fail_open' => $failOpen,
        'integrations' => ['wpforms' => $enabled],
        'wpforms_protect_all' => $protectAll,
    ] + $extra);
    $settings = new Settings;

    return new WpForms($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

/**
 * The form data WPForms hands to its hooks.
 *
 * @return array<string, mixed>
 */
function capWpFormData(string $protection = 'default', int $id = 5): array
{
    return ['id' => $id, 'fields' => [], 'settings' => ['ajax_submit' => '1', WpForms::MODE_KEY => $protection]];
}

/** Renders the submit area the way WPForms' Frontend::foot() does: our hook, the button, then the after hook. */
function capWpFormsFoot(WpForms $integration, ?array $formData = null): string
{
    $formData ??= capWpFormData();
    ob_start();
    $integration->renderWidget($formData);
    echo '<button type="submit" name="wpforms[submit]" id="wpforms-submit-5" class="wpforms-submit" aria-live="assertive" value="wpforms-submit">Submit</button>';
    $integration->wireSubmitButton($formData, 'submit');

    return (string) ob_get_clean();
}

function capWpFormsProcess(): CapWpFormsProcessStub
{
    return $GLOBALS['__cap_wpforms_objects']['process'];
}

it('identifies itself and is unavailable without WPForms', function (): void {
    $integration = capWpForms();

    expect($integration->id())->toBe('wpforms')
        ->and(Settings::SURFACES)->toContain('wpforms')
        ->and(defined('WPFORMS_VERSION'))->toBeFalse()
        ->and($integration->isAvailable())->toBeFalse();
});

it('registers on the WPForms render, builder and submission hooks', function (): void {
    capWpForms()->register();

    expect($GLOBALS['__cap_filters'])->toHaveKeys([
        'wpforms_display_submit_before',
        'wpforms_display_submit_after',
        'wpforms_admin_builder_anti_spam_panel_content',
        'wpforms_process',
        'wpforms_ajax_submit_errors_response',
        'wpforms_process_entry_saved',
    ])
        ->and($GLOBALS['__cap_filters']['wpforms_process'][0]['args'])->toBe(3)
        ->and($GLOBALS['__cap_filters']['wpforms_process_entry_saved'][0]['args'])->toBe(4);
});

it('decides per form: Always and Never override the global protect-all', function (bool $protectAll, string $protection, bool $expected): void {
    expect(capWpForms(protectAll: $protectAll)->isFormProtected(capWpFormData($protection)))->toBe($expected);
})->with([
    'default, protect-all on' => [true, 'default', true],
    'default, protect-all off' => [false, 'default', false],
    'always, protect-all off' => [false, 'always', true],
    'never, protect-all on' => [true, 'never', false],
    'unknown value falls back to default' => [true, 'sometimes', true],
]);

it('treats a form saved before the setting existed as Default', function (): void {
    expect(capWpForms(protectAll: true)->isFormProtected(['id' => 5, 'settings' => []]))->toBeTrue()
        ->and(capWpForms(protectAll: false)->isFormProtected(['id' => 5]))->toBeFalse();
});

it('protects no form while the integration is off, even one set to Always', function (): void {
    expect(capWpForms(enabled: false)->isFormProtected(capWpFormData('always')))->toBeFalse();
});

it('adds the Default / Always / Never select to the builder\'s spam protection panel', function (): void {
    ob_start();
    capWpForms()->renderFormSetting(capWpFormData('never'));
    $html = (string) ob_get_clean();

    $field = $GLOBALS['__cap_wpforms_panel_fields'][0];
    expect($field['option'])->toBe('select')
        ->and($field['panel'])->toBe('settings')
        ->and($field['field'])->toBe(WpForms::MODE_KEY)
        ->and(array_keys($field['args']['options']))->toBe(['default', 'always', 'never'])
        ->and($field['args']['default'])->toBe('default')
        ->and($html)->toContain('<select name="settings[cap_captcha_mode]" data-value="never">')
        ->and($html)->toContain('data-title="Privacy CAPTCHA for Cap"');
});

it('keeps the protect-all option while WPForms is inactive', function (): void {
    update_option(Settings::OPTION_KEY, ['integrations' => ['wpforms' => true], 'wpforms_protect_all' => true]);

    $saved = (new Settings)->sanitize(['integrations' => []]);

    expect($saved['integrations']['wpforms'])->toBeTrue()
        ->and($saved['wpforms_protect_all'])->toBeTrue();
});

it('defaults to off with protect-all off', function (): void {
    $settings = new Settings;

    expect($settings->isSurfaceEnabled('wpforms'))->toBeFalse()
        ->and($settings->isWpFormsProtectAll())->toBeFalse();
});

it('renders an inline widget with its own error message above the submit button', function (): void {
    $html = capWpFormsFoot(capWpForms());

    expect($html)->toMatch('#^<div class="cap-captcha--wpforms"><div class="cap-captcha cap-captcha--inline"><cap-widget id="cap-captcha-wpforms-\d+"#')
        ->and($html)->toContain('<div class="cap-captcha-wpforms-error wpforms-error" role="alert" hidden>CAPTCHA could not be started. Please try again.</div>')
        ->and($html)->toEndWith('<button type="submit" name="wpforms[submit]" id="wpforms-submit-5" class="wpforms-submit" aria-live="assertive" value="wpforms-submit">Submit</button>')
        ->and($GLOBALS['__cap_modules'])->toBe([Enqueuer::WIDGET_MODULE_ID])
        ->and($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-wpforms')
        ->and($GLOBALS['__cap_scripts']['cap-captcha-wpforms']['deps'])->toBe(['jquery']);
});

it('gives every rendered form its own widget, the same form twice included', function (): void {
    $integration = capWpForms();
    $first = capWpFormsFoot($integration);
    $second = capWpFormsFoot($integration);

    preg_match('#<cap-widget id="([^"]+)"#', $first, $a);
    preg_match('#<cap-widget id="([^"]+)"#', $second, $b);
    expect($a[1])->not->toBe($b[1]);
});

it('makes the WPForms submit button the floating trigger', function (): void {
    $html = capWpFormsFoot(capWpForms(mode: Settings::MODE_FLOATING));

    expect(preg_match('#<cap-widget id="(cap-captcha-wpforms-\d+)" data-cap-api-endpoint="https://cap.example.com/sitekey/" hidden#', $html, $widget))->toBe(1)
        ->and($html)->toContain('<button data-cap-floating="#'.$widget[1].'" data-cap-floating-position="top" type="submit" name="wpforms[submit]"')
        ->and($html)->not->toContain('cap-captcha-floating-trigger')
        ->and($html)->not->toContain('data-cap-autosubmit')
        ->and($GLOBALS['__cap_modules'])->toContain(Enqueuer::FLOATING_MODULE_ID);
});

it('keeps a trigger of its own in floating mode when no submit button follows', function (): void {
    $integration = capWpForms(mode: Settings::MODE_FLOATING);
    ob_start();
    $integration->renderWidget(capWpFormData());
    echo '<span class="custom-submit"></span>';
    $integration->wireSubmitButton(capWpFormData(), 'submit');
    $html = (string) ob_get_clean();

    expect($html)->toContain('data-cap-autosubmit')
        ->and($html)->toContain('cap-captcha-floating-trigger')
        ->and($html)->toEndWith('<span class="custom-submit"></span>');
});

it('ignores the after-submit hook of a page break or a form it did not render', function (): void {
    $integration = capWpForms(mode: Settings::MODE_FLOATING);
    ob_start();
    $integration->wireSubmitButton(capWpFormData(), 'next');
    $integration->wireSubmitButton(capWpFormData(), 'submit');

    expect((string) ob_get_clean())->toBe('');
});

it('renders a token field of its own in programmatic mode, outside the shared programmatic script', function (): void {
    $html = capWpFormsFoot(capWpForms(mode: Settings::MODE_PROGRAMMATIC));

    expect($html)->toStartWith('<div class="cap-captcha--wpforms"><input type="hidden" name="cap-token" class="cap-captcha cap-captcha--programmatic" data-cap-programmatic="1">')
        ->and($GLOBALS['__cap_modules'])->toBe([Enqueuer::WIDGET_MODULE_ID])
        ->and($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-wpforms');
});

it('renders nothing for an unprotected form, a surface that is off or an AMP page', function (WpForms $integration, string $protection, bool $amp): void {
    $GLOBALS['__cap_wpforms_amp'] = $amp;

    expect(capWpFormsFoot($integration, capWpFormData($protection)))->not->toContain('cap-captcha')
        ->and($GLOBALS['__cap_modules'])->toBe([]);
})->with([
    'never' => fn () => [capWpForms(), 'never', false],
    'default without protect-all' => fn () => [capWpForms(protectAll: false), 'default', false],
    'surface off' => fn () => [capWpForms(enabled: false), 'always', false],
    'AMP' => fn () => [capWpForms(), 'always', true],
]);

it('renders no widget while Cap is not configured', function (): void {
    $html = capWpFormsFoot(capWpForms(configured: false));

    expect($html)->not->toContain('<cap-widget')
        ->and($GLOBALS['__cap_modules'])->toBe([]);
});

it('lets a submission with a verified token through', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $_POST['cap-token'] = 'valid';

    capWpForms()->verify([], [], capWpFormData());

    $request = json_decode((string) $GLOBALS['__cap_remote_last_request']['args']['body'], true);
    expect($request['response'])->toBe('valid')
        ->and(capWpFormsProcess()->errors)->toBe([]);
});

it('refuses a rejected or missing token with an error above the submit button', function (?string $token): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    if ($token !== null) {
        $_POST['cap-token'] = $token;
    }

    capWpForms(failOpen: true)->verify([], [], capWpFormData());

    expect(capWpFormsProcess()->errors)->toBe([5 => ['footer' => 'CAPTCHA verification failed. Please try again.']]);
})->with([
    'rejected' => ['invalid'],
    'missing' => [null],
]);

it('refuses an unreachable Cap unless fail-open is on', function (bool $failOpen, array $errors): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'down');
    $_POST['cap-token'] = 'token';

    capWpForms(failOpen: $failOpen)->verify([], [], capWpFormData());

    expect(capWpFormsProcess()->errors)->toBe($errors);
})->with([
    'fail-closed' => [false, [5 => ['footer' => 'CAPTCHA verification failed. Please try again.']]],
    'fail-open' => [true, []],
]);

it('leaves a form that is not protected alone', function (): void {
    $_POST['cap-token'] = '';

    capWpForms()->verify([], [], capWpFormData('never'));

    expect(capWpFormsProcess()->errors)->toBe([])
        ->and($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull();
});

it('does not spend the token on a submission WPForms already refused', function (): void {
    $_POST['cap-token'] = 'valid';
    capWpFormsProcess()->errors = [5 => ['header' => 'Form has not been submitted, please see the errors below.', 3 => 'This field is required.']];
    $integration = capWpForms();

    $integration->verify([], [], capWpFormData());
    $response = $integration->markTokenKept(['errors' => ['field' => ['wpforms[fields][3]' => 'This field is required.']]], 5, capWpFormData());

    expect($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull()
        ->and(capWpFormsProcess()->errors[5])->not->toHaveKey('footer')
        ->and($response[WpForms::KEPT_KEY])->toBeTrue();
});

it('tells the script to renew the token once it went to Cap', function (string $body): void {
    $GLOBALS['__cap_remote_response'] = ['body' => $body];
    $_POST['cap-token'] = 'token';
    $integration = capWpForms();

    $integration->verify([], [], capWpFormData());
    $response = $integration->markTokenKept(['errors' => ['general' => ['footer' => 'x']]], 5, capWpFormData());

    expect($response)->not->toHaveKey(WpForms::KEPT_KEY);
})->with([
    'rejected' => ['{"success":false}'],
    // Verified, then a later handler (a payment, another add-on) refused the submission.
    'verified' => ['{"success":true}'],
]);

it('leaves the error response of an unprotected form alone', function (): void {
    $response = capWpForms()->markTokenKept(['errors' => []], 5, capWpFormData('never'));

    expect($response)->toBe(['errors' => []]);
});

it('marks a fail-open entry in WPForms Pro and fires the fail-open action', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'down');
    $GLOBALS['__cap_wpforms_pro'] = true;
    $meta = new CapWpFormsEntryMetaStub;
    $GLOBALS['__cap_wpforms_objects']['entry_meta'] = $meta;
    $_POST['cap-token'] = 'token';
    $integration = capWpForms(failOpen: true);

    $integration->verify([], [], capWpFormData());
    $integration->annotateFailOpen([], [], capWpFormData(), 42);
    $integration->annotateFailOpen([], [], capWpFormData(), 43);

    expect($meta->added)->toHaveCount(1)
        ->and($meta->added[0])->toMatchArray(['entry_id' => 42, 'form_id' => 5, 'type' => 'cap_captcha_fail_open', 'data' => 1])
        ->and($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'])->toBe([['wpforms', ['form_id' => 5, 'entry_id' => 42]]]);
});

it('fires the fail-open action without entry meta on WPForms Lite', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'down');
    $_POST['cap-token'] = 'token';
    $integration = capWpForms(failOpen: true);

    $integration->verify([], [], capWpFormData());
    $integration->annotateFailOpen([], [], capWpFormData(), 0);

    expect($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'])->toBe([['wpforms', ['form_id' => 5, 'entry_id' => 0]]]);
});

it('marks nothing after a verified submission', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $_POST['cap-token'] = 'token';
    $integration = capWpForms(failOpen: true);

    $integration->verify([], [], capWpFormData());
    $integration->annotateFailOpen([], [], capWpFormData(), 42);

    expect($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'] ?? [])->toBe([]);
});

it('verifies a submission that claims to come from an AMP page', function (): void {
    $GLOBALS['__cap_wpforms_amp'] = true;
    $_POST['__amp_form_verify'] = '1';

    capWpForms()->verify([], [], capWpFormData());

    expect(capWpFormsProcess()->errors)->toBe([5 => ['footer' => 'CAPTCHA verification failed. Please try again.']]);
});
