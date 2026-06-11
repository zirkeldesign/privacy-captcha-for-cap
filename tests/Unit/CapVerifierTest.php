<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Verification\CapVerifier;

beforeEach(function (): void {
    cap_reset_remote_stub();
});

it('returns true when the Cap server responds with success:true', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    $verifier = new CapVerifier('https://cap.example.com/', 'sitekey', 'secret');

    expect($verifier->verify('a-token'))->toBeTrue();
});

it('returns false when the Cap server responds with success:false', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];

    $verifier = new CapVerifier('https://cap.example.com/', 'sitekey', 'secret');

    expect($verifier->verify('a-token'))->toBeFalse();
});

it('returns false when the body is not valid JSON', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => 'not-json'];

    $verifier = new CapVerifier('https://cap.example.com/', 'sitekey', 'secret');

    expect($verifier->verify('a-token'))->toBeFalse();
});

it('returns false when wp_remote_post yields a WP_Error', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'boom');

    $verifier = new CapVerifier('https://cap.example.com/', 'sitekey', 'secret');

    expect($verifier->verify('a-token'))->toBeFalse();
});

it('returns false on empty token', function (): void {
    $verifier = new CapVerifier('https://cap.example.com/', 'sitekey', 'secret');

    expect($verifier->verify(''))->toBeFalse();
    expect($verifier->verify('   '))->toBeFalse();
});

it('returns false when not configured', function (): void {
    expect((new CapVerifier('', 'sitekey', 'secret'))->verify('t'))->toBeFalse();
    expect((new CapVerifier('https://cap.example.com/', '', 'secret'))->verify('t'))->toBeFalse();
    expect((new CapVerifier('https://cap.example.com/', 'sitekey', ''))->verify('t'))->toBeFalse();
});

it('builds the correct request URL and JSON body', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    $verifier = new CapVerifier('https://cap.example.com/', 'sitekey', 'secret');
    $verifier->verify('the-token');

    $request = $GLOBALS['__cap_remote_last_request'];

    expect($request['url'])->toBe('https://cap.example.com/sitekey/siteverify');
    expect($request['args']['headers']['Content-Type'])->toBe('application/json');

    $payload = json_decode((string) $request['args']['body'], true);
    expect($payload)->toBe(['secret' => 'secret', 'response' => 'the-token']);
});

it('normalises a trailing slash on the endpoint base', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    (new CapVerifier('https://cap.example.com', 'sitekey', 'secret'))->verify('t');

    expect($GLOBALS['__cap_remote_last_request']['url'])
        ->toBe('https://cap.example.com/sitekey/siteverify');
});
