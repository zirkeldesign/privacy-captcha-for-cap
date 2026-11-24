<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\Login;
use ZirkelDesign\CapCaptcha\Integration\WooCommerce;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
});

/**
 * @param  array<string, bool>  $integrations
 */
function capConfigure(array $integrations): Settings
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => false,
        'integrations' => $integrations,
    ]);

    return new Settings;
}

function capWoo(): WooCommerce
{
    $settings = capConfigure(['woocommerce' => true, 'woocommerce_registration' => true]);

    return new WooCommerce($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

function capLogin(): Login
{
    $settings = capConfigure(['login' => true]);

    return new Login($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

it('does not validate registration during checkout account creation', function (): void {
    // No woocommerce-register-nonce → this is checkout / programmatic, not the
    // My Account register form, so the CAPTCHA must not be required here.
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $errors = new WP_Error;

    $result = capWoo()->verifyRegistration($errors);

    expect($result->has_errors())->toBeFalse();
});

it('validates the My Account registration form', function (): void {
    $_POST['woocommerce-register-nonce'] = 'x';
    $errors = new WP_Error;

    $result = capWoo()->verifyRegistration($errors);

    expect($result->get_error_code())->toBe('cap_captcha_failed');
});

it('skips core Login verification for WooCommerce My Account logins', function (): void {
    $_POST['woocommerce-login-nonce'] = 'x';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $user = new WP_User;

    expect(capLogin()->verifyLogin($user, 'pw'))->toBe($user);
});

it('verifies a normal wp-login submission', function (): void {
    $_POST['wp-submit'] = 'Log In';
    $user = new WP_User;

    expect(capLogin()->verifyLogin($user, 'pw'))->toBeInstanceOf(WP_Error::class);
});

it('skips core Login verification for programmatic sign-ins', function (): void {
    // No wp-submit → wp_signon() from a membership plugin, XML-RPC or the REST
    // API. Those never render our widget, so gating them would lock people out.
    $user = new WP_User;

    expect(capLogin()->verifyLogin($user, 'pw'))->toBe($user);
});

it('still skips checkout account creation with fail-open on and no token', function (): void {
    // Regression guard for the empty-token fail-closed change: the
    // woocommerce-register-nonce check, not fail-open, is what keeps checkout
    // account creation working.
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => true,
        'integrations' => ['woocommerce' => true, 'woocommerce_registration' => true],
    ]);
    $settings = new Settings;
    $woo = new WooCommerce($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));

    expect($woo->verifyRegistration(new WP_Error)->has_errors())->toBeFalse();
});

it('verifies the checkout after WooCommerce has validated the form', function (): void {
    $GLOBALS['__cap_filters'] = [];

    capWoo()->register();

    expect($GLOBALS['__cap_filters'])->not->toHaveKey('woocommerce_checkout_process')
        ->and($GLOBALS['__cap_filters']['woocommerce_after_checkout_validation'][0]['args'] ?? null)->toBe(2);
});

it('keeps the token unspent while the checkout form has errors', function (): void {
    $_POST['cap-token'] = 'tok';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $errors = new WP_Error('billing_phone_required', 'x');

    capWoo()->verifyCheckout([], $errors);

    expect($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull()
        ->and($errors->get_error_codes())->toBe(['billing_phone_required']);
});

it('adds the CAPTCHA error to a valid checkout with a rejected token', function (): void {
    $_POST['cap-token'] = 'tok';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $errors = new WP_Error;

    capWoo()->verifyCheckout([], $errors);

    expect($errors->get_error_codes())->toBe(['cap_captcha_failed']);
});

it('loads the checkout script that renews the token after a failed checkout', function (): void {
    $GLOBALS['__cap_scripts'] = [];
    ob_start();
    capWoo()->renderWidget();
    ob_end_clean();

    expect($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-woocommerce-checkout')
        ->and($GLOBALS['__cap_scripts']['cap-captcha-woocommerce-checkout']['deps'])->toBe(['jquery']);
});
