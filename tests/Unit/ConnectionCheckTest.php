<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Status\ConnectionCheck;
use ZirkelDesign\CapCaptcha\Status\SecretProbe;
use ZirkelDesign\CapCaptcha\Status\SolverProbe;
use ZirkelDesign\CapCaptcha\Status\StatsClient;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_transients();
    cap_reset_remote_stub();
    capResetSettingsSingleton();
    capSiteverifyAnswers(404, 'Token not found');
});

/**
 * What /siteverify answers to the probe: "Token not found" once it has
 * accepted the secret.
 */
function capSiteverifyAnswers(int $status, string $error): void
{
    $GLOBALS['__cap_remote_response'] = [
        'response' => ['code' => $status],
        'body' => wp_json_encode(['success' => false, 'error' => $error]),
    ];
}

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
    return new ConnectionCheck($settings, new StatsClient($settings), new SolverProbe, new SecretProbe);
}

it('fails before any request when the endpoint, site key or secret is missing', function (): void {
    $settings = capConfigureConnection(['secret_key' => '']);

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull()
        ->and($GLOBALS['__cap_remote_get_requests'] ?? [])->toBe([]);
});

it('fails with the answer of the Cap server when it refuses the secret', function (): void {
    // What Cap 4 answers for a secret whose stored hash it no longer reads.
    $settings = capConfigureConnection();
    capSiteverifyAnswers(403, 'This key\'s secret was made by an older Cap release, and Cap no longer accepts it.');

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The Cap server refused the site key or secret key (HTTP 403: This key\'s secret was made by an older Cap release, and Cap no longer accepts it.).')
        ->and($GLOBALS['__cap_remote_get_requests'] ?? [])->toBe([]);
});

it('fails when the Cap server does not answer at all', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'boom');

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('did not answer');
});

it('fails when the endpoint is some other website', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_response'] = ['response' => ['code' => 200], 'body' => '<html>Welcome</html>'];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The endpoint did not answer like a Cap server (HTTP 200). Check the endpoint URL.');
});

it('passes without an API key and says the statistics stay off', function (): void {
    $settings = capConfigureConnection(['admin_api_key' => '']);

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Connected — the Cap server accepts the site key and secret key')
        ->and($result->warnings)->toBe(['No API key: the status panel and the dashboard statistics stay off.'])
        ->and($GLOBALS['__cap_remote_get_requests'] ?? [])->toBe([]);
});

it('probes both solvers without an API key and warns about the one that is missing', function (): void {
    $settings = capConfigureConnection(['admin_api_key' => '', 'wasm_source' => Settings::WASM_CAP_SERVER]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/assets/hashwx.wasm' => ['response' => ['code' => 503], 'body' => ''],
        '/assets/cap_wasm_bg.wasm' => ['response' => ['code' => 200], 'body' => "\0asm"],
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->warnings)->toHaveCount(2)
        ->and($result->warnings[1])->toBe('The Cap server does not serve hashwx.wasm (HTTP 503). Forms fail if the site key uses the hashwx protocol.');
});

it('fails without an API key when the Cap server serves neither solver', function (): void {
    $settings = capConfigureConnection(['admin_api_key' => '', 'wasm_source' => Settings::WASM_CAP_SERVER]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/assets/' => ['response' => ['code' => 404], 'body' => 'Not found.'],
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('does not serve hashwx.wasm (HTTP 404)');
});

it('fails with the answer of the Cap server when it refuses the API key', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => [
        'response' => ['code' => 401],
        'body' => '{"success":false,"error":"Unauthorized. Invalid bot token."}',
    ]];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The Cap server accepts the site key and secret key, but not the API key (HTTP 401: Unauthorized. Invalid bot token.).');
});

it('warns about an API key that reads other site keys', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx']),
        '/server/keys' => ['response' => ['code' => 200], 'body' => '[{"siteKey":"abc123"},{"siteKey":"other1"},{"siteKey":"other2"}]'],
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->warnings)->toBe(['The API key can read 3 site keys. Create one that is read-only and limited to this site key.']);
});

it('has nothing to say about an API key limited to its site key', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx']),
        '/server/keys' => ['response' => ['code' => 200], 'body' => '[{"siteKey":"abc123"}]'],
    ];

    expect(capCheck($settings)->run()->warnings)->toBe([]);
});

it('fails when the frame is isolated on a key that blocks on behavior', function (): void {
    $settings = capConfigureConnection(['isolate_frame' => true]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx', 'behavior' => 'block']),
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('the key rejects an isolated check');
});

it('fails when the frame is isolated on a key that blocks one interaction signal', function (): void {
    $settings = capConfigureConnection(['isolate_frame' => true]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx', 'signalActions' => ['interaction_synthetic_clicks' => 'block']]),
    ];

    expect(capCheck($settings)->run()->success)->toBeFalse();
});

it('does not blame the API key for an answer that is not from Cap', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_get_responses'] = ['/server/keys/' => ['response' => ['code' => 200], 'body' => '<html>Proxy</html>']];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The endpoint did not answer like a Cap server (HTTP 200). Check the endpoint URL.');
});

it('warns about the sha256 solver alone when it is the missing one', function (): void {
    $settings = capConfigureConnection(['admin_api_key' => '', 'wasm_source' => Settings::WASM_CAP_SERVER]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/assets/cap_wasm_bg.wasm' => ['response' => ['code' => 503], 'body' => ''],
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->warnings[1])->toContain('does not serve cap_wasm_bg.wasm (HTTP 503)')
        ->and($result->warnings[1])->toContain('sha256-pow protocol');
});

it('accepts an isolated frame on a key that only reports', function (): void {
    $settings = capConfigureConnection(['isolate_frame' => true]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx', 'behavior' => 'report', 'blockAgents' => 'off']),
    ];

    expect(capCheck($settings)->run()->success)->toBeTrue();
});

it('warns when the key wants a press that the display mode never gives', function (): void {
    $settings = capConfigureConnection(['display_mode' => Settings::MODE_FLOATING, 'isolate_frame' => false]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx', 'behavior' => 'block', 'requireInteraction' => true]),
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('requires a press on the widget');
});

it('names the key protocol and skips the solver probe with the bundled solvers', function (): void {
    $settings = capConfigureConnection();
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx', 'hashwxDifficulty' => 1_000_000]),
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Connected — site "Example" (abc123), hashwx key')
        ->and($result->warnings)->toBe([]);
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
        ->and(array_column($GLOBALS['__cap_remote_get_requests'], 'url'))->toContain('https://cap.example.test/assets/hashwx.wasm');
});

it('fails on a hashwx key when the Cap server answers 503 for hashwx.wasm', function (): void {
    // The Cap server answers 503 for a file its WASM version does not
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
        ->and(end($GLOBALS['__cap_remote_get_requests'])['url'])->toBe('https://cap.example.test/assets/cap_wasm_bg.wasm');
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

it('fails on a key that blocks on behavior while isolation is left at its default', function (): void {
    $settings = capConfigureConnection([]);
    $GLOBALS['__cap_remote_get_responses'] = [
        '/server/keys/' => capKeyResponse(['protocol' => 'hashwx', 'behavior' => 'block']),
    ];

    $result = capCheck($settings)->run();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('rejects an isolated check');
});
