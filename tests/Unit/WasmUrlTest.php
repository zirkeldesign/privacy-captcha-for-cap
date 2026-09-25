<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    capResetSettingsSingleton();
});

it('serves both solvers from the plugin by default', function (): void {
    $settings = new Settings;

    expect($settings->getSelfHostedWasmUrl())->toBe(CAP_CAPTCHA_URL.'assets/wasm/cap_wasm_bg.wasm');
    expect($settings->getSelfHostedHashwxUrl())->toBe(CAP_CAPTCHA_URL.'assets/wasm/hashwx.wasm');
});

it('ships the files the bundled urls point at', function (): void {
    // The widget has no JS fallback for hashwx and the build strips its CDN
    // fallback, so a missing file means hashwx keys cannot be solved at all.
    $settings = new Settings;

    foreach ([$settings->getSelfHostedWasmUrl(), $settings->getSelfHostedHashwxUrl()] as $url) {
        $path = dirname(__DIR__, 2).'/'.substr($url, strlen(CAP_CAPTCHA_URL));

        expect($path)->toBeFile();
    }
});

it('loads both solvers from the cap server when configured', function (): void {
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.test',
        'wasm_source' => Settings::WASM_CAP_SERVER,
    ]);
    capResetSettingsSingleton();

    $settings = new Settings;

    expect($settings->getSelfHostedWasmUrl())->toBe('https://cap.example.test/assets/cap_wasm_bg.wasm');
    expect($settings->getSelfHostedHashwxUrl())->toBe('https://cap.example.test/assets/hashwx.wasm');
});

it('leaves the urls empty when the cap server source has no endpoint', function (): void {
    update_option(Settings::OPTION_KEY, [
        'wasm_source' => Settings::WASM_CAP_SERVER,
    ]);
    capResetSettingsSingleton();

    $settings = new Settings;

    expect($settings->getSelfHostedWasmUrl())->toBe('');
    expect($settings->getSelfHostedHashwxUrl())->toBe('');
});
