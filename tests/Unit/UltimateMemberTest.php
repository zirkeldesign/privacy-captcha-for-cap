<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\UltimateMember;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/** Stands in for UM's form object: errors by field key, as UM()->form()->add_error() keeps them. */
final class CapUmFormStub
{
    /** @var array<string, string>|null */
    public ?array $errors = null;

    public function add_error(string $key, string $error): void
    {
        $this->errors[$key] ??= $error;
    }
}

if (! function_exists('UM')) {
    /** Ultimate Member's accessor, handing out the form and login state of the current test. */
    function UM(): object
    {
        return new class
        {
            public function form(): CapUmFormStub
            {
                return $GLOBALS['__cap_um_form'];
            }

            public function login(): object
            {
                return $GLOBALS['__cap_um_login'];
            }

            public function password(): object
            {
                return $GLOBALS['__cap_um_password'];
            }
        };
    }
}

/** @return array<string, string>|null */
function capUmErrors(): ?array
{
    return $GLOBALS['__cap_um_form']->errors;
}

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    cap_reset_remote_stub();
    $_POST = [];
    $GLOBALS['__cap_modules'] = [];
    $GLOBALS['__cap_um_form'] = new CapUmFormStub;
    $GLOBALS['__cap_um_login'] = (object) ['auth_id' => ''];
    $GLOBALS['__cap_um_password'] = new stdClass;
    $GLOBALS['__cap_removed_actions'] = [];
    $GLOBALS['__cap_user_meta'] = [];
});

function capUltimateMember(
    bool $enabled = true,
    bool $failOpen = false,
    bool $configured = true,
    string $mode = Settings::MODE_INLINE,
): UltimateMember {
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => $configured ? 'https://cap.example.com/' : '',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'display_mode' => $mode,
        'fail_open' => $failOpen,
        'integrations' => ['ultimate_member' => $enabled],
    ]);
    $settings = new Settings;

    return new UltimateMember($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

/** Renders the way `um_after_login_fields` does: our widget, UM's submit button at 1000, then our release at 1001. */
function capUmLoginForm(UltimateMember $integration, int $formId = 5): string
{
    ob_start();
    $integration->renderWidget([], $formId);
    echo '<div class="um-center"><input type="submit" value="Login" class="um-button" id="um-submit-btn" /></div>';
    $integration->wireSubmitButton();

    return (string) ob_get_clean();
}

it('identifies itself and is unavailable without Ultimate Member', function (): void {
    $integration = capUltimateMember();

    expect($integration->id())->toBe('ultimate_member')
        ->and(Settings::SURFACES)->toContain('ultimate_member')
        ->and($integration->isAvailable())->toBeFalse();
});

it('registers on the UM form and error hooks', function (): void {
    capUltimateMember()->register();

    expect($GLOBALS['__cap_filters'])->toHaveKeys([
        'um_after_login_fields',
        'um_after_register_fields',
        'um_after_password_reset_fields',
        'um_submit_form_errors_hook_login',
        'um_submit_form_errors_hook__registration',
        'um_reset_password_errors_hook',
        'um_submit_form_errors_hook',
        'um_before_save_filter_submitted',
        'user_register',
    ]);
});

it('renders an inline widget above the submit button', function (): void {
    $html = capUmLoginForm(capUltimateMember());

    expect($html)->toMatch('#<div class="um-field cap-captcha--ultimate-member"><div class="cap-captcha cap-captcha--inline"><cap-widget id="cap-captcha-um-\d+"#')
        ->and($html)->toContain('<input type="submit" value="Login" class="um-button" id="um-submit-btn" />')
        ->and($GLOBALS['__cap_modules'])->toBe([Enqueuer::WIDGET_MODULE_ID]);
});

it('makes the UM submit button the floating trigger', function (): void {
    $html = capUmLoginForm(capUltimateMember(mode: Settings::MODE_FLOATING));

    expect($html)->toMatch('#<cap-widget id="(cap-captcha-um-\d+)" data-cap-api-endpoint="https://cap.example.com/sitekey/" hidden#')
        ->and($html)->toMatch('#<input data-cap-floating="\#cap-captcha-um-\d+" data-cap-floating-position="top" type="submit"#')
        ->and($html)->not->toContain('<button')
        ->and($GLOBALS['__cap_modules'])->toContain(Enqueuer::FLOATING_MODULE_ID);
});

it('keeps a trigger of its own when no submit button follows', function (): void {
    $integration = capUltimateMember(mode: Settings::MODE_FLOATING);
    ob_start();
    $integration->renderWidget([], 5);
    echo '<a href="/register">Register</a>';
    $integration->wireSubmitButton();
    $html = (string) ob_get_clean();

    expect($html)->toContain('data-cap-autosubmit')
        ->and($html)->toContain('<button type="button"')
        ->and($html)->toEndWith('<a href="/register">Register</a>');

    // The buffer is closed: a second release prints nothing of its own.
    ob_start();
    $integration->wireSubmitButton();
    expect((string) ob_get_clean())->toBe('');
});

it('renders a token field in programmatic mode and on the reset form', function (): void {
    $integration = capUltimateMember(mode: Settings::MODE_PROGRAMMATIC);
    $login = capUmLoginForm($integration);
    ob_start();
    $integration->renderResetWidget();
    $reset = (string) ob_get_clean();

    expect($login)->toContain('<input type="hidden" name="cap-token"')
        ->and($reset)->toBe('<div class="um-field cap-captcha--ultimate-member"><input type="hidden" name="cap-token" class="cap-captcha cap-captcha--programmatic" data-cap-programmatic="1"></div>')
        ->and($GLOBALS['__cap_modules'])->toContain(Enqueuer::PROGRAMMATIC_MODULE_ID);
});

it('renders nothing when the surface is off or not configured', function (): void {
    $button = '<div class="um-center"><input type="submit" value="Login" class="um-button" id="um-submit-btn" /></div>';

    expect(capUmLoginForm(capUltimateMember(false)))->toBe($button)
        ->and(capUmLoginForm(capUltimateMember(configured: false)))->toBe($button)
        ->and($GLOBALS['__cap_modules'])->toBe([]);
});

it('lets a submission with a valid token through', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $integration = capUltimateMember();

    $integration->validateLogin(['form_id' => 5, 'cap-token' => 'valid']);

    $request = json_decode((string) $GLOBALS['__cap_remote_last_request']['args']['body'], true);
    expect($request['response'])->toBe('valid')
        ->and(capUmErrors())->toBeNull()
        ->and($GLOBALS['__cap_removed_actions'])->toBe([]);
});

it('adds a UM error for an invalid or missing token', function (array $submitted): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];

    capUltimateMember(failOpen: true)->validateRegistration($submitted);

    expect(capUmErrors())->toBe(['cap-token' => 'CAPTCHA verification failed. Please try again.']);
})->with([
    'invalid' => [['form_id' => 5, 'cap-token' => 'invalid']],
    'missing' => [['form_id' => 5]],
    'not a string' => [['form_id' => 5, 'cap-token' => ['x']]],
]);

it('keeps UM from testing the password once the login check failed, and reports the failed login', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $GLOBALS['__cap_actions'] = [];

    capUltimateMember()->validateLogin(['form_id' => 5, 'username' => 'jane', 'cap-token' => 'invalid']);

    [$username, $error] = $GLOBALS['__cap_actions']['wp_login_failed'][0];
    expect($GLOBALS['__cap_removed_actions'])->toBe([
        'um_submit_form_errors_hook_login' => ['um_submit_form_errors_hook_login'],
    ])
        ->and($username)->toBe('jane')
        ->and($error->errors)->toHaveKey('cap_captcha_failed');
});

it('keeps UM from counting a failed reset request against the reset limit', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];

    capUltimateMember()->validateReset(['mode' => 'password', 'username_b' => 'jane']);

    expect($GLOBALS['__cap_removed_actions'])->toBe([
        'um_reset_password_errors_hook' => [[$GLOBALS['__cap_um_password'], 'um_reset_password_errors_hook']],
    ]);
});

it('leaves only the CAPTCHA error after a failed registration, so it tells nothing about existing accounts', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $integration = capUltimateMember();

    $integration->validateRegistration(['form_id' => 4, 'cap-token' => 'invalid']);
    UM()->form()->add_error('user_login', 'The username you entered is incorrect');
    $integration->concealOutcome();

    expect(capUmErrors())->toBe(['cap-token' => 'CAPTCHA verification failed. Please try again.'])
        ->and($GLOBALS['__cap_removed_actions'])->toBe([]);
});

it('leaves only the CAPTCHA error and no user id once every login validator has run', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $integration = capUltimateMember();

    $integration->validateLogin(['form_id' => 5, 'cap-token' => 'invalid']);
    // What a renamed UM password handler would still do after us.
    $GLOBALS['__cap_um_login']->auth_id = 7;
    UM()->form()->add_error('user_password', 'Password is incorrect. Please try again.');
    $integration->concealOutcome();

    expect(capUmErrors())->toBe(['cap-token' => 'CAPTCHA verification failed. Please try again.'])
        ->and($GLOBALS['__cap_um_login']->auth_id)->toBe('');
});

it('conceals nothing for a solved check that follows a failed one', function (): void {
    $integration = capUltimateMember();
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $integration->validateLogin(['form_id' => 5, 'cap-token' => 'first']);
    $GLOBALS['__cap_um_form'] = new CapUmFormStub;

    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $integration->validateLogin(['form_id' => 5, 'cap-token' => 'second']);
    $integration->concealOutcome();

    expect(capUmErrors())->toBeNull();
});

it('leaves the login errors of a solved check alone', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $integration = capUltimateMember();

    $integration->validateLogin(['form_id' => 5, 'cap-token' => 'valid']);
    UM()->form()->add_error('user_password', 'Password is incorrect. Please try again.');
    $integration->concealOutcome();

    expect(capUmErrors())->toBe(['user_password' => 'Password is incorrect. Please try again.']);
});

it('shows the error only under the form that failed', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $integration = capUltimateMember();
    $integration->validateReset(['mode' => 'password', 'cap-token' => 'invalid']);

    ob_start();
    $integration->renderResetWidget();
    $reset = (string) ob_get_clean();

    expect($reset)->toContain('<div class="um-field-error" role="alert">CAPTCHA verification failed. Please try again.</div>')
        ->and(capUmLoginForm($integration))->not->toContain('um-field-error');
});

it('leaves the error on the login form to UM, which lists it above the fields', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $integration = capUltimateMember();
    $integration->validateRegistration(['form_id' => 5, 'cap-token' => 'invalid']);

    $render = static function (string $mode) use ($integration): string {
        ob_start();
        $integration->renderWidget(['mode' => $mode], 5);

        return (string) ob_get_clean();
    };

    expect($render('register'))->toContain('um-field-error')
        ->and($render('login'))->not->toContain('um-field-error');
});

it('leaves submissions alone when the surface is off', function (): void {
    capUltimateMember(false)->validateLogin(['form_id' => 5]);

    expect(capUmErrors())->toBeNull()
        ->and($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull();
});

it('keeps the token out of the submitted user meta', function (): void {
    expect(capUltimateMember()->removeToken(['user_login' => 'jane', 'cap-token' => 'abc']))->toBe(['user_login' => 'jane']);
});

it('marks a registration that passed only through fail-open', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('unreachable', 'unreachable');
    $GLOBALS['__cap_actions'] = [];
    $integration = capUltimateMember(failOpen: true);

    $integration->validateRegistration(['form_id' => 5, 'cap-token' => 'token']);
    $integration->annotateFailOpen(42);
    $integration->annotateFailOpen(43);

    expect($GLOBALS['__cap_user_meta'])->toBe([42 => ['cap_captcha_fail_open' => 1]])
        ->and($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'])->toBe([['ultimate_member', ['user_id' => 42]]])
        ->and(capUmErrors())->toBeNull();
});

it('keeps the Ultimate Member setting when that plugin is unavailable', function (): void {
    update_option(Settings::OPTION_KEY, ['integrations' => ['ultimate_member' => true]]);

    $saved = (new Settings)->sanitize(['integrations' => []]);

    expect($saved['integrations']['ultimate_member'])->toBeTrue();
});
