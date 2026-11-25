<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\GravityForms\Field;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Status\StatsClient;
use ZirkelDesign\CapCaptcha\Status\StoredKeyPolicy;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    cap_reset_transients();
    cap_reset_remote_stub();
    capResetSettingsSingleton();

    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.test',
        'site_key' => 'abc123',
        'secret_key' => 'deadbeef',
        'admin_api_key' => 'id_token',
        'display_mode' => Settings::MODE_FLOATING,
    ]);
});

/**
 * @param  array<string, mixed>  $config
 */
function capServeKeyConfig(array $config): void
{
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => [
        'response' => ['code' => 200],
        'body' => wp_json_encode(['key' => ['siteKey' => 'abc123', 'config' => $config], 'stats' => []]),
    ]];
}

it('renders the configured mode while nothing is known about the key', function (): void {
    expect(Settings::get_instance()->getDisplayMode())->toBe(Settings::MODE_FLOATING);
});

it('renders inline once the key is read and requires a press on the widget', function (): void {
    capServeKeyConfig(['behavior' => 'block', 'requireInteraction' => true]);

    (new StatsClient(Settings::get_instance()))->fetch(true);

    expect(Settings::get_instance()->getDisplayMode())->toBe(Settings::MODE_INLINE)
        ->and(Settings::get_instance()->getConfiguredDisplayMode())->toBe(Settings::MODE_FLOATING)
        ->and(Settings::get_instance()->isFloating())->toBeFalse();
});

it('returns to the configured mode when the key stops requiring a press', function (): void {
    (new StoredKeyPolicy)->remember(['key' => ['config' => ['behavior' => 'block', 'requireInteraction' => true]]]);
    capServeKeyConfig(['behavior' => 'report', 'requireInteraction' => true]);

    (new StatsClient(Settings::get_instance()))->fetch(true);

    expect(Settings::get_instance()->getDisplayMode())->toBe(Settings::MODE_FLOATING);
});

it('keeps the last known rules when the Cap server does not answer', function (): void {
    (new StoredKeyPolicy)->remember(['key' => ['config' => ['behavior' => 'block', 'requireInteraction' => true]]]);
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => new WP_Error('http_request_failed', 'boom')];

    (new StatsClient(Settings::get_instance()))->fetch(true);

    expect(Settings::get_instance()->getDisplayMode())->toBe(Settings::MODE_INLINE);
});

it('does not learn the rules of a key that is only being tested', function (): void {
    capServeKeyConfig(['behavior' => 'block', 'requireInteraction' => true]);

    (new StatsClient(Settings::get_instance()))->read();

    expect((new StoredKeyPolicy)->requiresInteraction())->toBeFalse();
});

it('forgets the rules with the cached status when the settings change', function (): void {
    (new StoredKeyPolicy)->remember(['key' => ['config' => ['behavior' => 'block', 'requireInteraction' => true]]]);

    (new StatsClient(Settings::get_instance()))->flush();

    expect((new StoredKeyPolicy)->requiresInteraction())->toBeFalse();
});

it('overrides the mode chosen on a Gravity Forms field', function (): void {
    (new StoredKeyPolicy)->remember(['key' => ['config' => ['behavior' => 'block', 'requireInteraction' => true]]]);

    $field = new Field(['capDisplayMode' => Settings::MODE_PROGRAMMATIC]);

    expect($field->resolveDisplayMode())->toBe(Settings::MODE_INLINE);
});

it('leaves the mode of a Gravity Forms field alone otherwise', function (): void {
    $field = new Field(['capDisplayMode' => Settings::MODE_PROGRAMMATIC]);

    expect($field->resolveDisplayMode())->toBe(Settings::MODE_PROGRAMMATIC);
});

it('lets a filter keep the configured mode', function (): void {
    (new StoredKeyPolicy)->remember(['key' => ['config' => ['behavior' => 'block', 'requireInteraction' => true]]]);
    add_filter('cap_captcha_force_inline', fn (): bool => false);

    expect(Settings::get_instance()->getDisplayMode())->toBe(Settings::MODE_FLOATING);
});

it('reports a mode without a press as unchecked while the rules of the key were never read', function (): void {
    expect(Settings::get_instance()->isDisplayModeUnchecked())->toBeTrue();
});

it('reports it as unchecked when the Cap server refuses the API key', function (): void {
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => ['response' => ['code' => 403], 'body' => '{}']];

    (new StatsClient(Settings::get_instance()))->fetch(true);

    expect(Settings::get_instance()->isDisplayModeUnchecked())->toBeTrue();
});

it('reports the mode as checked once the key is read, whatever its rules', function (array $config): void {
    capServeKeyConfig($config);

    (new StatsClient(Settings::get_instance()))->fetch(true);

    expect(Settings::get_instance()->isDisplayModeUnchecked())->toBeFalse();
})->with([
    'a key that requires a press' => [['behavior' => 'block', 'requireInteraction' => true]],
    'a key that does not' => [['behavior' => 'report']],
]);

it('has nothing to check for the inline mode', function (): void {
    update_option(Settings::OPTION_KEY, ['display_mode' => Settings::MODE_INLINE] + get_option(Settings::OPTION_KEY));
    capResetSettingsSingleton();

    expect(Settings::get_instance()->isDisplayModeUnchecked())->toBeFalse();
});
