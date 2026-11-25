<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\LostPassword;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    unset($GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD']);
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
});

afterEach(function (): void {
    unset($GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD']);
});

function capLostPasswordPostTo(string $page): void
{
    $GLOBALS['pagenow'] = $page;
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

function capLostPassword(bool $enabled = true): LostPassword
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => false,
        'integrations' => ['lost_password' => $enabled],
    ]);
    $settings = new Settings;

    return new LostPassword($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

it('blocks a wp-login lost-password request without a token', function (): void {
    capLostPasswordPostTo('wp-login.php');
    $_POST['wp-submit'] = 'Get New Password';
    $errors = new WP_Error;

    capLostPassword()->verifyLostPassword($errors);

    expect($errors->get_error_codes())->toBe(['cap_captcha_failed']);
});

it('answers the same for unknown and known accounts when the check fails', function (): void {
    capLostPasswordPostTo('wp-login.php');
    $_POST['wp-submit'] = 'Get New Password';
    // retrieve_password() adds this before lostpassword_post for an unknown email.
    $unknown = new WP_Error('invalid_email', 'There is no account with that username or email address.');
    $known = new WP_Error;

    capLostPassword()->verifyLostPassword($unknown);
    capLostPassword()->verifyLostPassword($known);

    expect($unknown->errors)->toBe($known->errors);
});

it('lets a request with a valid token through untouched', function (): void {
    capLostPasswordPostTo('wp-login.php');
    $_POST['wp-submit'] = 'Get New Password';
    $_POST['cap-token'] = 'tok';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $errors = new WP_Error('invalid_email', 'x');

    capLostPassword()->verifyLostPassword($errors);

    expect($errors->get_error_codes())->toBe(['invalid_email']);
});

it('verifies a wp-login.php request that leaves out the submit button', function (): void {
    capLostPasswordPostTo('wp-login.php');
    $_POST = ['user_login' => 'admin'];
    $errors = new WP_Error;

    capLostPassword()->verifyLostPassword($errors);

    expect($errors->get_error_codes())->toBe(['cap_captcha_failed']);
});

it('verifies a wp-login.php request that carries a WooCommerce lost-password nonce', function (): void {
    capLostPasswordPostTo('wp-login.php');
    $_POST = ['user_login' => 'admin', 'wp-submit' => 'x', 'woocommerce-lost-password-nonce' => 'x'];
    $errors = new WP_Error;

    capLostPassword()->verifyLostPassword($errors);

    expect($errors->get_error_codes())->toBe(['cap_captcha_failed']);
});

it('leaves the WooCommerce lost-password form to its own surface', function (): void {
    capLostPasswordPostTo('index.php');
    $_POST = ['user_login' => 'admin', 'wc_reset_password' => 'true', 'woocommerce-lost-password-nonce' => 'x'];
    $errors = new WP_Error;

    capLostPassword()->verifyLostPassword($errors);

    expect($errors->has_errors())->toBeFalse();
});

it('skips programmatic password resets', function (): void {
    $errors = new WP_Error;

    capLostPassword()->verifyLostPassword($errors);

    expect($errors->has_errors())->toBeFalse();
});

it('does nothing while the surface is off', function (): void {
    capLostPasswordPostTo('wp-login.php');
    $_POST['wp-submit'] = 'Get New Password';
    $errors = new WP_Error;

    capLostPassword(false)->verifyLostPassword($errors);

    expect($errors->has_errors())->toBeFalse();
});
