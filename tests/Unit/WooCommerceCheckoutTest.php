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
    unset($GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD']);
    $GLOBALS['__cap_wc_error_notices'] = 0;
    // The checkout tests run inside WC_Checkout::process_checkout().
    $GLOBALS['__cap_did_actions'] = ['woocommerce_checkout_process' => 1];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
});

afterEach(function (): void {
    unset($GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD'], $GLOBALS['__cap_did_actions']);
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
    $settings = capConfigure(['woocommerce' => true, 'woocommerce_registration' => true, 'woocommerce_lost_password' => true]);

    return new WooCommerce($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

function capLogin(): Login
{
    $settings = capConfigure(['login' => true]);

    return new Login($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

it('hooks registration only where the My Account handler asks, not at checkout account creation', function (): void {
    $GLOBALS['__cap_filters'] = [];

    capWoo()->register();

    expect($GLOBALS['__cap_filters'])->toHaveKey('woocommerce_process_registration_errors')
        ->and($GLOBALS['__cap_filters'])->not->toHaveKey('woocommerce_registration_errors');
});

it('validates the My Account registration form whatever name its nonce is sent under', function (array $post): void {
    // WooCommerce also takes the nonce as `_wpnonce`, so no field of the
    // request may decide whether the check runs.
    $_POST = $post;

    expect(capWoo()->verifyRegistration(new WP_Error)->get_error_code())->toBe('cap_captcha_failed');
})->with([
    'the form as rendered' => [['register' => 'Register', 'email' => 'a@b.test', 'woocommerce-register-nonce' => 'x']],
    'nonce as _wpnonce' => [['register' => 'Register', 'email' => 'a@b.test', '_wpnonce' => 'x']],
]);

it('validates the My Account lost-password form whatever name its nonce is sent under', function (array $post): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['pagenow'] = 'index.php';
    $_POST = $post;
    $errors = new WP_Error;

    capWoo()->verifyLostPassword($errors);

    expect($errors->get_error_codes())->toBe(['cap_captcha_failed']);
})->with([
    'the form as rendered' => [['wc_reset_password' => 'true', 'user_login' => 'admin', 'woocommerce-lost-password-nonce' => 'x']],
    'nonce as _wpnonce' => [['wc_reset_password' => 'true', 'user_login' => 'admin', '_wpnonce' => 'x']],
    'nonce in the URL only' => [['wc_reset_password' => 'true', 'user_login' => 'admin']],
]);

it('leaves a reset that WooCommerce does not handle alone', function (): void {
    $_POST = ['user_login' => 'admin'];
    $errors = new WP_Error;

    capWoo()->verifyLostPassword($errors);

    expect($errors->has_errors())->toBeFalse();
});

it('skips core Login verification for WooCommerce My Account logins', function (): void {
    $_POST['woocommerce-login-nonce'] = 'x';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $user = new WP_User;

    expect(capLogin()->verifyLogin($user, 'pw'))->toBe($user);
});

it('verifies a normal wp-login submission', function (): void {
    $GLOBALS['pagenow'] = 'wp-login.php';
    $_SERVER['REQUEST_METHOD'] = 'POST';
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

it('verifies the checkout after WooCommerce has validated the form', function (): void {
    $GLOBALS['__cap_filters'] = [];

    capWoo()->register();

    expect($GLOBALS['__cap_filters'])->not->toHaveKey('woocommerce_checkout_process')
        ->and($GLOBALS['__cap_filters']['woocommerce_after_checkout_validation'][0]['args'] ?? null)->toBe(2)
        ->and($GLOBALS['__cap_filters']['woocommerce_after_checkout_validation'][0]['priority'] ?? null)->toBe(PHP_INT_MAX);
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

it('leaves the errors unmarked once the token was sent to Cap', function (): void {
    $_POST['cap-token'] = 'tok';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $woo = capWoo();
    $woo->register();

    $woo->verifyCheckout([], new WP_Error);

    expect(apply_filters('woocommerce_add_error', 'Payment declined.'))->toBe('Payment declined.');
});

it('marks the errors of a form whose token was kept', function (): void {
    $_POST['cap-token'] = 'tok';
    $woo = capWoo();
    $woo->register();

    $woo->verifyCheckout([], new WP_Error('billing_email_required', 'x'));

    expect(apply_filters('woocommerce_add_error', 'Billing Email address is a required field.'))
        ->toContain('cap-captcha-kept')
        ->and(apply_filters('woocommerce_add_error', apply_filters('woocommerce_add_error', 'x')))
        ->toBe(apply_filters('woocommerce_add_error', 'x'));
});

it('keeps the token while an earlier error notice is pending', function (): void {
    $_POST['cap-token'] = 'tok';
    $GLOBALS['__cap_wc_error_notices'] = 1;

    capWoo()->verifyCheckout([], new WP_Error);

    expect($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull();
});

it('refuses an order whose token was skipped because of errors', function (): void {
    $_POST['cap-token'] = 'tok';
    $woo = capWoo();

    $woo->verifyCheckout([], new WP_Error('billing_phone_required', ''));

    expect(fn () => $woo->refuseUncheckedOrder())->toThrow(Exception::class);
});

it('lets a checked order be created', function (): void {
    $_POST['cap-token'] = 'tok';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $woo = capWoo();

    $woo->verifyCheckout([], new WP_Error);
    $woo->refuseUncheckedOrder();

    expect(true)->toBeTrue();
});

it('keeps the token on an "Update totals" submit', function (): void {
    $_POST['cap-token'] = 'tok';

    capWoo()->verifyCheckout(['woocommerce_checkout_update_totals' => true], new WP_Error);

    expect($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull();
});

it('loads the checkout script that renews the token after a failed checkout', function (): void {
    $GLOBALS['__cap_scripts'] = [];
    ob_start();
    capWoo()->renderWidget();
    ob_end_clean();

    expect($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-woocommerce-checkout')
        ->and($GLOBALS['__cap_scripts']['cap-captcha-woocommerce-checkout']['deps'])->toBe(['jquery']);
});

it('passes a notice that is not a string through unchanged', function (): void {
    $_POST['cap-token'] = 'tok';
    $woo = capWoo();
    $woo->register();
    $woo->verifyCheckout([], new WP_Error('billing_email_required', 'x'));

    expect(apply_filters('woocommerce_add_error', null))->toBeNull();
});

it('leaves the token alone when a gateway validates the form ahead of the order', function (): void {
    // PayPal Payments calls validate_checkout() when its button is pressed,
    // outside process_checkout(). Spending the token there fails the real submit.
    $GLOBALS['__cap_did_actions'] = [];
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST['cap-token'] = 'solved-token';
    $errors = new WP_Error;
    $woo = capWoo();

    $woo->verifyCheckout([], $errors);
    $woo->refuseUncheckedOrder();

    expect($errors->has_errors())->toBeFalse()
        ->and($GLOBALS['__cap_remote_post_count'] ?? 0)->toBe(0);
});
