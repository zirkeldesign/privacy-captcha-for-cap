<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Status\StatsClient;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_transients();
    cap_reset_remote_stub();
    capResetSettingsSingleton();

    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.test',
        'site_key' => 'abc123',
        'secret_key' => 'deadbeef',
        'admin_api_key' => 'id_token',
    ]);
});

it('keeps what the Cap server answered when it refuses the API key', function (): void {
    // What Cap 4 answers for an API key created on Cap 3.
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => [
        'response' => ['code' => 401],
        'body' => '{"success":false,"error":"This API key was made by an older Cap release, and Cap no longer accepts it."}',
    ]];
    $client = new StatsClient(new Settings);

    expect($client->fetch(true))->toBeNull()
        ->and($client->failure()?->describe())->toBe('HTTP 401: This API key was made by an older Cap release, and Cap no longer accepts it.');
});

it('has no failure to report after an answer it could read', function (): void {
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => [
        'response' => ['code' => 200],
        'body' => '{"key":{"siteKey":"abc123"},"stats":{}}',
    ]];
    $client = new StatsClient(new Settings);

    expect($client->fetch(true))->toBeArray()
        ->and($client->failure())->toBeNull();
});

it('does not cache a refusal', function (): void {
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => ['response' => ['code' => 500], 'body' => '']];

    (new StatsClient(new Settings))->fetch(true);

    expect(get_transient(StatsClient::CACHE_KEY))->toBeFalse();
});

it('counts the site keys the API key can read', function (): void {
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys' => [
        'response' => ['code' => 200],
        'body' => '[{"siteKey":"abc123"},{"siteKey":"other"}]',
    ]];

    expect((new StatsClient(new Settings))->siteKeyCount())->toBe(2);
});

it('does not count when the answer is not a list', function (): void {
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys' => [
        'response' => ['code' => 403],
        'body' => '{"success":false,"error":"Forbidden"}',
    ]];

    expect((new StatsClient(new Settings))->siteKeyCount())->toBeNull();
});

it('cuts the error text of an endpoint that is not Cap to a readable length', function (): void {
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => [
        'response' => ['code' => 500],
        'body' => wp_json_encode(['error' => str_repeat('x', 5000)]),
    ]];
    $client = new StatsClient(new Settings);
    $client->fetch(true);

    expect(strlen($client->failure()->serverMessage))->toBe(300);
});

it('drops the cached status when asked to flush', function (): void {
    set_transient('cap_captcha_stats', ['stale' => true], 300);

    (new StatsClient(Settings::get_instance()))->flush();

    expect(get_transient('cap_captcha_stats'))->toBeFalse();
});
