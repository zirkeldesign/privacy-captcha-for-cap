<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;

/*
 * Runs Ultimate Member's own form handlers (UM()->form()->form_init() and
 * UM()->password()->form_init()) in this process. UM redirects and exits once a
 * submission is valid, so the hook that would start that throws instead: a
 * CapUmReached means UM accepted the submission.
 */

final class CapUmReached extends RuntimeException {}

function capUmStopAt(string $hook): void
{
    add_action($hook, static function () use ($hook): void {
        throw new CapUmReached($hook);
    }, 0);
}

/** UM redirects, then exits, when a login reveals an account status. */
function capUmStopAtRedirect(string $location): never
{
    throw new CapUmReached('redirect '.(string) wp_parse_url($location, PHP_URL_QUERY));
}

/** @return array<string, string> UM's errors for the submission, or ['reached' => hook] when UM accepted it. */
function capUmSubmit(array $post, bool $reset = false): array
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = wp_slash($post);
    UM()->form()->errors = null;

    try {
        $reset ? UM()->password()->form_init() : UM()->form()->form_init();
    } catch (CapUmReached $reached) {
        return ['reached' => $reached->getMessage()];
    }

    return (array) UM()->form()->errors;
}

function capUmForm(string $mode): int
{
    return (int) (get_option('um_core_forms', [])[$mode] ?? 0);
}

/** @param array<string, string> $fields */
function capUmPost(string $mode, array $fields): array
{
    $formId = capUmForm($mode);

    return [
        'form_id' => (string) $formId,
        'um_request' => '',
        '_wpnonce' => wp_create_nonce("um_{$mode}_form{$formId}"),
        ...array_combine(array_map(static fn (string $key): string => "{$key}-{$formId}", array_keys($fields)), $fields),
    ];
}

/** Answers Cap's /siteverify: true verifies, false rejects. */
function capUmCap(bool $verified): void
{
    add_filter('pre_http_request', static function ($preempt, $args, $url) use ($verified) {
        if ($preempt !== false || ! str_contains((string) $url, '/siteverify')) {
            return $preempt;
        }

        return [
            'headers' => [],
            'body' => wp_json_encode(['success' => $verified]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }, 10, 3);
}

function capUmRender(string $shortcode, string $mode = Settings::MODE_INLINE): string
{
    $forget = static fn () => (new ReflectionProperty(Settings::class, 'cache'))->setValue(Settings::get_instance(), null);
    $withMode = static fn ($settings): array => ['display_mode' => $mode] + (array) $settings;
    add_filter('option_'.Settings::OPTION_KEY, $withMode);
    $forget();
    try {
        return do_shortcode($shortcode);
    } finally {
        remove_filter('option_'.Settings::OPTION_KEY, $withMode);
        $forget();
    }
}

beforeEach(function (): void {
    if (! defined('UM_VERSION')) {
        $this->markTestSkipped('Ultimate Member is not active in the WordPress fixture.');
    }

    // UM creates its core forms on the first admin visit, which the fixture never makes.
    if (capUmForm('login') === 0) {
        wp_set_current_user(1);
        UM()->setup()->install_default_forms();
    }
    wp_set_current_user(0);

    $jane = get_user_by('login', 'umjane');
    $janeId = $jane ? $jane->ID : wp_insert_user(['user_login' => 'umjane', 'user_pass' => 'correct-horse', 'user_email' => 'umjane@example.test']);
    // UM counts reset requests per user and refuses them past its limit.
    delete_user_meta((int) $janeId, 'password_rst_attempts');

    add_filter('cap_captcha_protect_ultimate_member', '__return_true');
    capUmStopAt('um_user_login');
    capUmStopAt('um_registration_complete');
    capUmStopAt('um_reset_password_process_hook');
    add_filter('wp_redirect', 'capUmStopAtRedirect', 0);
});

afterEach(function (): void {
    $_POST = [];
    remove_all_filters('pre_http_request', 10);
    remove_all_filters('cap_captcha_protect_ultimate_member');
    remove_all_filters('cap_captcha_protect_login');
    remove_all_actions('um_user_login', 0);
    remove_all_actions('um_registration_complete', 0);
    remove_all_actions('um_reset_password_process_hook', 0);
    remove_filter('wp_redirect', 'capUmStopAtRedirect', 0);
    // A failed check takes UM's password handler off for the rest of the request; this process is one request.
    if (function_exists('um_submit_form_errors_hook_login') && ! has_action('um_submit_form_errors_hook_login', 'um_submit_form_errors_hook_login')) {
        add_action('um_submit_form_errors_hook_login', 'um_submit_form_errors_hook_login');
    }
});

it('renders the widget above the submit button of all three UM forms', function (string $shortcode): void {
    $html = capUmRender($shortcode);

    expect($html)->toContain('cap-captcha--ultimate-member')
        ->and(strpos($html, '<cap-widget'))->toBeLessThan(strpos($html, 'id="um-submit-btn"'));
})->with([
    'login' => fn () => '[ultimatemember form_id="'.capUmForm('login').'"]',
    'register' => fn () => '[ultimatemember form_id="'.capUmForm('register').'"]',
    'password reset' => ['[ultimatemember_password]'],
]);

it('turns the real login and register buttons into the floating trigger', function (string $mode): void {
    $html = capUmRender('[ultimatemember form_id="'.capUmForm($mode).'"]', Settings::MODE_FLOATING);

    expect(preg_match('/<cap-widget id="([^"]+)"/', $html, $widget))->toBe(1)
        ->and($html)->toMatch('/<input data-cap-floating="#'.preg_quote($widget[1], '/').'"[^>]*type="submit"[^>]*id="um-submit-btn"/')
        ->and($html)->not->toContain('cap-captcha-floating-trigger');
})->with(['login', 'register']);

it('keeps a trigger of its own on the reset form in floating mode', function (): void {
    expect(capUmRender('[ultimatemember_password]', Settings::MODE_FLOATING))->toContain('cap-captcha-floating-trigger');
});

it('renders a token field in programmatic mode', function (): void {
    expect(capUmRender('[ultimatemember form_id="'.capUmForm('login').'"]', Settings::MODE_PROGRAMMATIC))
        ->toContain('<input type="hidden" name="cap-token" class="cap-captcha cap-captcha--programmatic"');
});

it('logs in with a valid token', function (): void {
    capUmCap(true);

    expect(capUmSubmit(capUmPost('login', ['username' => 'umjane', 'user_password' => 'correct-horse']) + ['cap-token' => 'ok']))
        ->toBe(['reached' => 'um_user_login']);
});

it('rejects a login without a valid token and does not say whether the password was right', function (?string $token): void {
    capUmCap(false);
    $extra = $token === null ? [] : ['cap-token' => $token];

    $right = capUmSubmit(capUmPost('login', ['username' => 'umjane', 'user_password' => 'correct-horse']) + $extra);
    $wrong = capUmSubmit(capUmPost('login', ['username' => 'umjane', 'user_password' => 'wrong']) + $extra);

    expect($right)->toBe(['cap-token' => 'CAPTCHA verification failed. Please try again.'])
        ->and($wrong)->toBe($right);
})->with(['missing' => [null], 'rejected' => ['bad']]);

it('still hides whether the password was right when UM renames its password handler', function (): void {
    capUmCap(false);
    // remove_action() no longer finds the handler by its old name.
    $renamed = static fn (array $submitted) => um_submit_form_errors_hook_login($submitted);
    remove_action('um_submit_form_errors_hook_login', 'um_submit_form_errors_hook_login');
    add_action('um_submit_form_errors_hook_login', $renamed);

    try {
        $right = capUmSubmit(capUmPost('login', ['username' => 'umjane', 'user_password' => 'correct-horse']) + ['cap-token' => 'bad-renamed']);
        $wrong = capUmSubmit(capUmPost('login', ['username' => 'umjane', 'user_password' => 'wrong']) + ['cap-token' => 'bad-renamed']);
    } finally {
        remove_action('um_submit_form_errors_hook_login', $renamed);
    }

    expect($right)->toBe(['cap-token' => 'CAPTCHA verification failed. Please try again.'])
        ->and($wrong)->toBe($right);
});

it('does not give away a pending account behind a failed check', function (bool $verified): void {
    capUmCap($verified);
    $pending = get_user_by('login', 'umpending');
    $pendingId = $pending ? $pending->ID : wp_insert_user(['user_login' => 'umpending', 'user_pass' => 'correct-horse', 'user_email' => 'umpending@example.test']);
    update_user_meta((int) $pendingId, 'account_status', 'awaiting_admin_review');
    $renamed = static fn (array $submitted) => um_submit_form_errors_hook_login($submitted);
    remove_action('um_submit_form_errors_hook_login', 'um_submit_form_errors_hook_login');
    add_action('um_submit_form_errors_hook_login', $renamed);

    try {
        $result = capUmSubmit(capUmPost('login', ['username' => 'umpending', 'user_password' => 'correct-horse']) + ['cap-token' => $verified ? 'pending-ok' : 'pending-bad']);
    } finally {
        remove_action('um_submit_form_errors_hook_login', $renamed);
    }

    // The passed check is the control: UM does redirect a pending account with the right password.
    expect($result)->toBe($verified
        ? ['reached' => 'redirect err=awaiting_admin_review']
        : ['cap-token' => 'CAPTCHA verification failed. Please try again.']);
})->with(['failed check' => [false], 'passed check' => [true]]);

it('leaves UM logins to their own toggle when the core login surface is on', function (): void {
    remove_all_filters('cap_captcha_protect_ultimate_member');
    add_filter('cap_captcha_protect_ultimate_member', '__return_false');
    add_filter('cap_captcha_protect_login', '__return_true');

    expect(capUmSubmit(capUmPost('login', ['username' => 'umjane', 'user_password' => 'correct-horse'])))
        ->toBe(['reached' => 'um_user_login']);
});

it('registers with a valid token and keeps the token out of the saved submission', function (): void {
    capUmCap(true);
    $login = 'umnew'.wp_rand();

    $result = capUmSubmit(capUmPost('register', [
        'user_login' => $login,
        'user_email' => "{$login}@example.test",
        'user_password' => 'Str0ng-Passw0rd!',
        'confirm_user_password' => 'Str0ng-Passw0rd!',
        'first_name' => 'New',
        'last_name' => 'Member',
    ]) + ['cap-token' => 'ok']);

    $user = get_user_by('login', $login);
    expect($result)->toBe(['reached' => 'um_registration_complete'])
        ->and($user)->toBeInstanceOf(WP_User::class)
        ->and(get_user_meta($user->ID, 'submitted', true))->toBeArray()->not->toHaveKey('cap-token');
});

it('rejects a registration without a valid token', function (): void {
    capUmCap(false);
    $login = 'umbot'.wp_rand();

    $result = capUmSubmit(capUmPost('register', [
        'user_login' => $login,
        'user_email' => "{$login}@example.test",
        'user_password' => 'Str0ng-Passw0rd!',
        'confirm_user_password' => 'Str0ng-Passw0rd!',
    ]) + ['cap-token' => 'bad']);

    expect($result)->toHaveKey('cap-token')
        ->and(get_user_by('login', $login))->toBeFalse();
});

it('checks the token on the password reset form', function (bool $verified): void {
    capUmCap($verified);

    $result = capUmSubmit(['_um_password_reset' => '1', 'username_b' => 'umjane', 'cap-token' => $verified ? 'reset-ok' : 'reset-bad'], true);

    expect($result)->toBe($verified
        ? ['reached' => 'um_reset_password_process_hook']
        : ['cap-token' => 'CAPTCHA verification failed. Please try again.']);
})->with(['valid' => [true], 'rejected' => [false]]);

it('does not count tokenless reset requests against the reset limit or say the limit exists', function (): void {
    capUmCap(false);
    $jane = get_user_by('login', 'umjane');

    $answers = [];
    foreach (range(1, 4) as $attempt) {
        $answers[] = capUmSubmit(['_um_password_reset' => '1', 'username_b' => 'umjane', 'cap-token' => "flood-{$attempt}"], true);
    }

    remove_all_filters('pre_http_request', 10);
    capUmCap(true);
    $legit = capUmSubmit(['_um_password_reset' => '1', 'username_b' => 'umjane', 'cap-token' => 'after-flood-ok'], true);

    expect(array_unique($answers, SORT_REGULAR))->toBe([['cap-token' => 'CAPTCHA verification failed. Please try again.']])
        ->and((int) get_user_meta($jane->ID, 'password_rst_attempts', true))->toBe(0)
        ->and($legit)->toBe(['reached' => 'um_reset_password_process_hook']);
});

it('does not say whether a username or email address is taken behind a failed check', function (string $login): void {
    capUmCap(false);

    $result = capUmSubmit(capUmPost('register', [
        'user_login' => $login,
        'user_email' => "{$login}@example.test",
        'user_password' => 'Str0ng-Passw0rd!',
        'confirm_user_password' => 'Str0ng-Passw0rd!',
    ]) + ['cap-token' => "taken-{$login}"]);

    expect($result)->toBe(['cap-token' => 'CAPTCHA verification failed. Please try again.']);
})->with(['taken' => ['umjane'], 'free' => ['umnobody']]);

it('reports a login that failed the check to lockout plugins', function (): void {
    capUmCap(false);
    $failed = [];
    $record = static function (string $username, WP_Error $error) use (&$failed): void {
        $failed[] = [$username, $error->get_error_code()];
    };
    add_action('wp_login_failed', $record, 10, 2);

    try {
        capUmSubmit(capUmPost('login', ['username' => 'umjane', 'user_password' => 'wrong']) + ['cap-token' => 'lockout-bad']);
    } finally {
        remove_action('wp_login_failed', $record, 10);
    }

    expect($failed)->toBe([['umjane', 'cap_captcha_failed']]);
});
