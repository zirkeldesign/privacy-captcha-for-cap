<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Settings;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    capResetSettingsSingleton();
});

it('points the widget at the bundled solvers and leaves the frame on the page origin', function (): void {
    $globals = (new Enqueuer(new Settings))->globals();

    expect($globals)->toHaveKeys(['CAP_CUSTOM_WASM_URL', 'CAP_CUSTOM_HASHWX_URL', 'CAP_PAKO_URL'])
        ->and($globals)->not->toHaveKey('CAP_ISOLATE');
});

it('isolates the instrumentation frame when the setting is on', function (): void {
    update_option(Settings::OPTION_KEY, ['isolate_frame' => true]);

    expect((new Enqueuer(new Settings))->globals()['CAP_ISOLATE'])->toBeTrue();
});

it('lets a filter isolate the frame on chosen requests', function (): void {
    add_filter('cap_captcha_isolate_frame', fn (): bool => true);

    expect((new Enqueuer(new Settings))->globals()['CAP_ISOLATE'])->toBeTrue();
});

it('passes the widget its labels for a check the page blocked', function (): void {
    update_option(Settings::OPTION_KEY, ['endpoint_base' => 'https://cap.example.test', 'site_key' => 'abc123', 'secret_key' => 'deadbeef']);
    capResetSettingsSingleton();

    $html = (new ZirkelDesign\CapCaptcha\Asset\Renderer(Settings::get_instance()))->renderForMode('cap-test', 'inline');

    expect($html)->toContain('data-cap-i18n-csp-error-label="Blocked by this page"')
        ->and($html)->toContain('data-cap-i18n-troubleshooting-label="Troubleshoot"');
});
