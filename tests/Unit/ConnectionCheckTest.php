<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Status\ConnectionCheck;
use ZirkelDesign\CapCaptcha\Status\SolverProbe;
use ZirkelDesign\CapCaptcha\Status\StatsClient;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_transients();
    cap_reset_remote_stub();
    capResetSettingsSingleton();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function capConfigureConnection(array $overrides = []): Settings
{
    update_option(Settings::OPTION_KEY, array_merge([
        'endpoint_base' => 'https://cap.example.test',
        'site_key' => 'abc123',
        'secret_key' => 'deadbeef',
        'admin_api_key' => 'admin-key',
        'wasm_source' => Settings::WASM_BUNDLED,
    ], $overrides));
    capResetSettingsSingleton();

    return new Settings;
}

/**
 * The /server/keys/:siteKey payload for a key with the given config.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function capKeyResponse(array $config): array
{
    return [
        'response' => ['code' => 200],
        'body' => wp_json_encode([
            'key' => ['siteKey' => 'abc123', 'name' => 'Example', 'config' => $config],
            'stats' => [],
        ]),
    ];
}

function capCheck(Settings $settings): ConnectionCheck
{
    return new ConnectionCheck($settings, new StatsClient($settings), new SolverProbe);
}

it('fails before any request when the endpoint, site key or secret is missing', function (): void {
    $settings = capConfigureConnection(['secret_key' => '']);

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($GLOBALS['__cap_remote_get_requests'] ?? [])->toBe([]);
});

it('fails before any request without an admin API key', function (): void {
    $settings = capConfigureConnection(['admin_api_key' => '']);

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Admin API key')
        ->and($GLOBALS['__cap_remote_get_requests'] ?? [])->toBe([]);
});

it('fails when the Cap server does not answer the key request', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => new WP_Error('http_request_failed', 'boom')];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('did not return a valid response');
});

it('names the key protocol and skips the solver probe with the bundled solvers', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx', 'hashwxDifficulty' => 1_000_000]),
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Connected — site "Example" (abc123), hashwx key')
        ->and($GLOBALS['__cap_remote_get_requests'])->toHaveCount(1);
});

it('reports a solver the Cap server serves', function (): void {
    $settings = capConfigureConnection(['wasm_source' => Settings::WASM_CAP_SERVER]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx']),
        '/assets/hashwx.wasm' => ['response' => ['code' => 200], 'body' => "\0asm"],
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Connected — site "Example" (abc123), hashwx key · hashwx.wasm served by the Cap server')
        ->and($GLOBALS['__cap_remote_get_requests'][1]['url'])->toBe('https://cap.example.test/assets/hashwx.wasm');
});

it('fails on a hashwx key when the Cap server answers 503 for hashwx.wasm', function (): void {
    // Cap Standalone answers 503 for a file its pinned WASM_VERSION does not
    // ship, which is hashwx.wasm below @cap.js/wasm 0.0.8. siteverify still
    // works on such a server, so this is the only place the problem shows.
    $settings = capConfigureConnection(['wasm_source' => Settings::WASM_CAP_SERVER]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx']),
        '/assets/hashwx.wasm' => ['response' => ['code' => 503], 'body' => 'Asset not cached yet.'],
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toStartWith('Connected — site "Example" (abc123), hashwx key, but the Cap server does not serve hashwx.wasm (HTTP 503).')
        ->and($result->message)->toContain('cannot solve a hashwx challenge');
});

it('probes the sha256 solver for a key without a protocol field', function (): void {
    $settings = capConfigureConnection(['wasm_source' => Settings::WASM_CAP_SERVER]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['difficulty' => 4, 'challengeCount' => 50, 'saltSize' => 32]),
        '/assets/cap_wasm_bg.wasm' => ['response' => ['code' => 404], 'body' => 'Asset server is disabled.'],
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('sha256-pow key, but the Cap server does not serve cap_wasm_bg.wasm (HTTP 404)')
        ->and($GLOBALS['__cap_remote_get_requests'][1]['url'])->toBe('https://cap.example.test/assets/cap_wasm_bg.wasm');
});

it('says so when the solver request gets no response at all', function (): void {
    $settings = capConfigureConnection(['wasm_source' => Settings::WASM_CAP_SERVER]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx']),
        '/assets/hashwx.wasm' => new WP_Error('http_request_failed', 'timeout'),
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('does not serve hashwx.wasm (no response)');
});
