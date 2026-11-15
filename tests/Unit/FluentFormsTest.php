<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\FluentForms;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    cap_reset_remote_stub();
    $_POST = [];
});

function capFluentForms(bool $enabled = true, bool $failOpen = false, bool $configured = true): FluentForms
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => $configured ? 'https://cap.example.com/' : '',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => $failOpen,
        'integrations' => ['fluent_forms' => $enabled],
    ]);
    $settings = new Settings;

    return new FluentForms($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

function capFluentFields(): array
{
    return ['cap-token' => ['element' => FluentForms::ELEMENT, 'raw' => ['attributes' => ['name' => 'cap-token']]]];
}

it('registers the Fluent Forms editor, renderer and submission hooks', function (): void {
    $integration = capFluentForms();
    $integration->register();

    expect($GLOBALS['__cap_filters'])
        ->toHaveKeys(['fluentform/editor_components', 'fluentform/form_input_types', 'fluentform/render_item_cap_captcha', 'fluentform/validation_errors', 'fluentform/insert_response_data']);
    $component = $integration->addComponent([])['advanced'][0];
    expect($component['element'])->toBe(FluentForms::ELEMENT)
        ->and($component['attributes']['name'])->toBe('cap-token')
        ->and($integration->addInputType([]))->toContain(FluentForms::ELEMENT);
});

it('requires a token only when the enabled field is present', function (): void {
    $integration = capFluentForms();
    $form = (object) ['id' => 1];

    expect($integration->validate([], [], $form, []))->toBe([])
        ->and($integration->validate([], [], $form, capFluentFields()))->toHaveKey('cap-token')
        ->and(capFluentForms(false)->validate([], [], $form, capFluentFields()))->toBe([])
        ->and(capFluentForms(true, false, false)->validate([], [], $form, capFluentFields()))->toBe([]);
});

it('verifies the token from Fluent Forms parsed submission data', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $_POST['cap-token'] = 'wrong-top-level-token';
    $integration = capFluentForms();

    expect($integration->validate([], ['cap-token' => 'fluent-token'], (object) ['id' => 1], capFluentFields()))->toBe([]);
    $request = json_decode((string) $GLOBALS['__cap_remote_last_request']['args']['body'], true);
    expect($request['response'])->toBe('fluent-token');
});

it('rejects invalid tokens even when fail-open is enabled', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $errors = capFluentForms(true, true)->validate([], ['cap-token' => 'invalid'], (object) ['id' => 1], capFluentFields());
    expect($errors)->toHaveKey('cap-token');
});

it('applies the Fluent Forms fail-open policy to unreachable servers', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('unreachable', 'unreachable');
    $form = (object) ['id' => 1];
    expect(capFluentForms(true, true)->validate([], ['cap-token' => 'token'], $form, capFluentFields()))->toBe([])
        ->and(capFluentForms(true, false)->validate([], ['cap-token' => 'token'], $form, capFluentFields()))->toHaveKey('cap-token');
});

it('removes only the CAPTCHA field token before storing the submission', function (): void {
    $integration = capFluentForms();
    $data = ['email' => 'visitor@example.com', 'cap-token' => 'token'];
    expect($integration->removeToken($data, 1, capFluentFields()))->toBe(['email' => 'visitor@example.com'])
        ->and($integration->removeToken($data, 1, []))->toBe($data);
});