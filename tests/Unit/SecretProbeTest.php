<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Status\SecretProbe;

beforeEach(function (): void {
    cap_reset_remote_stub();
});

/**
 * @return array<string, mixed>
 */
function capSiteverifyResponse(int $status, string $error): array
{
    return ['response' => ['code' => $status], 'body' => wp_json_encode(['success' => false, 'error' => $error])];
}

it('takes "Token not found" as an accepted secret', function (): void {
    $GLOBALS['__cap_remote_response'] = capSiteverifyResponse(404, 'Token not found');

    expect((new SecretProbe)->failure('https://cap.example.test/', 'abc123', 'deadbeef'))->toBeNull();
});

it('sends the secret with a token in the shape siteverify expects', function (): void {
    $GLOBALS['__cap_remote_response'] = capSiteverifyResponse(404, 'Token not found');

    (new SecretProbe)->failure('https://cap.example.test', 'abc123', 'deadbeef');

    $request = $GLOBALS['__cap_remote_last_request'];
    $body = json_decode($request['args']['body'], true);

    expect($request['url'])->toBe('https://cap.example.test/abc123/siteverify')
        ->and($body['secret'])->toBe('deadbeef')
        ->and(explode(':', $body['response']))->toHaveCount(3)
        ->and($body['response'])->toStartWith('abc123:');
});

it('reports a wrong secret with the status and the words of the Cap server', function (): void {
    $GLOBALS['__cap_remote_response'] = capSiteverifyResponse(403, 'Invalid site key or secret');

    $failure = (new SecretProbe)->failure('https://cap.example.test/', 'abc123', 'wrong');

    expect($failure?->describe())->toBe('HTTP 403: Invalid site key or secret');
});

it('reports an unknown site key, which answers 404 like an unknown token', function (): void {
    $GLOBALS['__cap_remote_response'] = capSiteverifyResponse(404, 'Invalid site key or secret');

    expect((new SecretProbe)->failure('https://cap.example.test/', 'nope', 'deadbeef')?->status)->toBe(404);
});

it('reports a server that does not answer', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'boom');

    $failure = (new SecretProbe)->failure('https://cap.example.test/', 'abc123', 'deadbeef');

    expect($failure?->status)->toBeNull()
        ->and($failure?->describe())->toBe('no response');
});

it('does not take a page that is not the Cap API for an accepted secret', function (): void {
    $GLOBALS['__cap_remote_response'] = ['response' => ['code' => 200], 'body' => '<html>Welcome</html>'];

    expect((new SecretProbe)->failure('https://example.test/', 'abc123', 'deadbeef')?->describe())->toBe('HTTP 200');
});
