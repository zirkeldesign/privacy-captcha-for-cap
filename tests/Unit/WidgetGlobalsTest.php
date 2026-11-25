<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Settings;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    capResetSettingsSingleton();
});

it('points the widget at the bundled solvers and isolates the frame by default', function (): void {
    $globals = (new Enqueuer(new Settings))->globals();

    expect($globals)->toHaveKeys(['CAP_CUSTOM_WASM_URL', 'CAP_CUSTOM_HASHWX_URL', 'CAP_PAKO_URL'])
        ->and($globals['CAP_ISOLATE'])->toBeTrue();
});

it('leaves the frame on the page origin when the setting is off', function (): void {
    update_option(Settings::OPTION_KEY, ['isolate_frame' => false]);

    expect((new Enqueuer(new Settings))->globals())->not->toHaveKey('CAP_ISOLATE');
});

it('lets a filter open the frame on chosen requests', function (): void {
    add_filter('cap_captcha_isolate_frame', fn (): bool => false);

    expect((new Enqueuer(new Settings))->globals())->not->toHaveKey('CAP_ISOLATE');
});

it('passes the widget its labels for a check the page blocked', function (): void {
    update_option(Settings::OPTION_KEY, ['endpoint_base' => 'https://cap.example.test', 'site_key' => 'abc123', 'secret_key' => 'deadbeef']);
    capResetSettingsSingleton();

    $html = (new ZirkelDesign\CapCaptcha\Asset\Renderer(Settings::get_instance()))->renderForMode('cap-test', 'inline');

    expect($html)->toContain('data-cap-i18n-csp-error-label="Blocked by this page"')
        ->and($html)->toContain('data-cap-i18n-troubleshooting-label="Troubleshoot"');
});

it('gives the programmatic script a message for a challenge that could not be solved', function (): void {
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.test',
        'site_key' => 'abc123',
        'secret_key' => 'deadbeef',
        'display_mode' => Settings::MODE_PROGRAMMATIC,
    ]);
    capResetSettingsSingleton();

    $globals = (new Enqueuer(Settings::get_instance()))->globals();

    expect($globals['CAP_CAPTCHA_PROGRAMMATIC']['errorMessage'])->toBeString()->not->toBeEmpty();
});
