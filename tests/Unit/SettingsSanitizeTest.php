<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    capResetSettingsSingleton();
});

it('defaults every integration to off', function (): void {
    // Gravity Forms used to default to on, a leftover from when the plugin was
    // Gravity Forms-only. On a site without it the card showed ticked.
    $settings = new Settings;

    foreach (Settings::SURFACES as $surface) {
        expect($settings->isIntegrationEnabled($surface))->toBeFalse();
    }
});

it('keeps an enabled integration whose plugin is inactive when settings are saved', function (): void {
    // Gravity Forms is not loaded in this environment, so its card renders
    // disabled — and disabled checkboxes are never submitted. Reading the POST
    // alone would switch the integration off behind the admin's back.
    update_option(Settings::OPTION_KEY, [
        'integrations' => ['gravity_forms' => true, 'comments' => true],
    ]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize([
        'integrations' => ['comments' => '1'],
    ]);

    expect($saved['integrations']['gravity_forms'])->toBeTrue();
    expect($saved['integrations']['comments'])->toBeTrue();
});

it('still lets an available integration be switched off', function (): void {
    // Comments needs no host plugin, so its card is always enabled and an
    // absent checkbox genuinely means "the admin unticked it".
    update_option(Settings::OPTION_KEY, [
        'integrations' => ['comments' => true, 'login' => true],
    ]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize([
        'integrations' => ['login' => '1'],
    ]);

    expect($saved['integrations']['comments'])->toBeFalse();
    expect($saved['integrations']['login'])->toBeTrue();
});

it('does not resurrect an unavailable integration that was already off', function (): void {
    update_option(Settings::OPTION_KEY, ['integrations' => ['gravity_forms' => false]]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize(['integrations' => []]);

    expect($saved['integrations']['gravity_forms'])->toBeFalse();
});

it('preserves the Fluent Forms setting when that plugin is unavailable', function (): void {
    update_option(Settings::OPTION_KEY, ['integrations' => ['fluent_forms' => true]]);

    $saved = Settings::get_instance()->sanitize(['integrations' => []]);

    expect($saved['integrations']['fluent_forms'])->toBeTrue();
});

it('preserves the WooCommerce master toggle too', function (): void {
    update_option(Settings::OPTION_KEY, [
        'integrations' => ['woocommerce' => true, 'woocommerce_checkout' => true],
    ]);
    capResetSettingsSingleton();

    $saved = Settings::get_instance()->sanitize(['integrations' => []]);

    expect($saved['integrations']['woocommerce'])->toBeTrue();
    expect($saved['integrations']['woocommerce_checkout'])->toBeTrue();
});

it('isolates the Cap check until the box is unticked and saved', function (): void {
    $settings = Settings::get_instance();

    // Nothing stored yet, as on a site that updates from a version without
    // the setting: the frame stays as closed off as cap-widget 0.1.58 kept it.
    expect($settings->isFrameIsolated())->toBeTrue()
        ->and($settings->sanitize(['isolate_frame' => '1'])['isolate_frame'])->toBeTrue()
        ->and($settings->sanitize([])['isolate_frame'])->toBeFalse();
});

it('previews a submitted form without touching what is saved', function (): void {
    update_option(Settings::OPTION_KEY, ['site_key' => 'saved', 'secret_key' => 'kept']);
    capResetSettingsSingleton();
    $settings = Settings::get_instance();

    $preview = $settings->withSubmitted(['site_key' => 'typed', 'secret_key' => 'kept', 'admin_api_key' => " id_token\n"]);

    expect($preview->getSiteKey())->toBe('typed')
        ->and($preview->getAdminApiKey())->toBe('id_token')
        ->and($settings->getSiteKey())->toBe('saved')
        ->and(get_option(Settings::OPTION_KEY)['site_key'])->toBe('saved');
});

it('keeps a wp-config secret away from an endpoint that is only typed into the form', function (): void {
    // A constant cannot be undefined again, so this case runs in its own process.
    $script = <<<'PHP'
    define('CAP_CAPTCHA_SECRET_KEY', 'locked');
    require 'tests/bootstrap.php';
    update_option(ZirkelDesign\CapCaptcha\Settings::OPTION_KEY, ['endpoint_base' => 'https://cap.example.test/', 'site_key' => 'saved']);
    $preview = ZirkelDesign\CapCaptcha\Settings::get_instance()->withSubmitted(['endpoint_base' => 'https://elsewhere.example.test/', 'site_key' => 'typed']);
    echo $preview->getEndpointBase(), '|', $preview->getSiteKey(), '|', $preview->getSecretKey(), '|', var_export($preview->typedEndpointSkipped, true);
    PHP;

    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1', $output, $exit);

    expect($exit)->toBe(0)
        ->and(implode('', $output))->toBe('https://cap.example.test/|typed|locked|true');
});

it('previews a typed endpoint when no secret is a wp-config constant', function (): void {
    update_option(Settings::OPTION_KEY, ['endpoint_base' => 'https://cap.example.test/']);
    capResetSettingsSingleton();

    $preview = Settings::get_instance()->withSubmitted(['endpoint_base' => 'https://elsewhere.example.test/']);

    expect($preview->getEndpointBase())->toBe('https://elsewhere.example.test/')
        ->and($preview->typedEndpointSkipped)->toBeFalse();
});
