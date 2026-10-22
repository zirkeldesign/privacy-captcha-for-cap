<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\Login;
use ZirkelDesign\CapCaptcha\Integration\Registration;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

if (! function_exists('wp_enqueue_script_module')) {
    function wp_enqueue_script_module(string $id, string $src = '', array $deps = [], string|false|null $version = false): void
    {
        $GLOBALS['__cap_modules'][] = $id;
    }
}

if (! function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void {}
}

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    $GLOBALS['__cap_modules'] = [];
    unset($GLOBALS['action']);
});

afterEach(function (): void {
    unset($GLOBALS['action']);
});

/**
 * Enqueues the widget the way wp-login.php does: every integration hooked to
 * login_enqueue_scripts runs on every screen.
 *
 * @param  array<string, bool>  $integrations
 * @return list<string>
 */
function capLoginScreenModules(?string $action, array $integrations): array
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'integrations' => $integrations,
    ]);

    if ($action !== null) {
        $GLOBALS['action'] = $action;
    }

    $settings = new Settings;
    $enqueuer = new Enqueuer($settings);
    $renderer = new Renderer($settings);
    $verifier = new TokenVerifier($settings);

    (new Login($settings, $renderer, $enqueuer, $verifier))->enqueueAssets();
    (new Registration($settings, $renderer, $enqueuer, $verifier))->enqueueAssets();

    return $GLOBALS['__cap_modules'];
}

it('does not load the widget on the login screen when only registration is protected', function (): void {
    expect(capLoginScreenModules('login', ['login' => false, 'registration' => true]))->toBe([]);
});

it('does not load the widget on the register screen when only login is protected', function (): void {
    expect(capLoginScreenModules('register', ['login' => true, 'registration' => false]))->toBe([]);
});

it('does not load the widget on screens without a protected form', function (): void {
    expect(capLoginScreenModules('lostpassword', ['login' => true, 'registration' => true]))->toBe([]);
});

it('loads the widget on the screen of a protected form', function (?string $action, array $integrations): void {
    expect(capLoginScreenModules($action, $integrations))->toContain(Enqueuer::WIDGET_MODULE_ID);
})->with([
    'login' => ['login', ['login' => true]],
    'login without action global' => [null, ['login' => true]],
    'register' => ['register', ['registration' => true]],
]);
