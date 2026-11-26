<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\Login;
use ZirkelDesign\CapCaptcha\Integration\Registration;
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

function capLoginScreenSettings(): Settings
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => false,
        'integrations' => ['login' => true, 'registration' => true, 'woocommerce' => true, 'woocommerce_login' => true],
    ]);

    return new Settings;
}

function capLoginScreenLogin(): Login
{
    $settings = capLoginScreenSettings();

    return new Login($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

function capLoginScreenRegistration(): Registration
{
    $settings = capLoginScreenSettings();

    return new Registration($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

function capPostTo(string $page): void
{
    $GLOBALS['pagenow'] = $page;
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

it('verifies a wp-login.php login that leaves out the submit button', function (): void {
    capPostTo('wp-login.php');
    $_POST = ['log' => 'admin', 'pwd' => 'secret'];

    expect(capLoginScreenLogin()->verifyLogin(new WP_User, 'secret'))->toBeInstanceOf(WP_Error::class);
});

it('verifies a wp-login.php login that carries a WooCommerce login nonce', function (): void {
    capPostTo('wp-login.php');
    $_POST = ['log' => 'admin', 'pwd' => 'secret', 'woocommerce-login-nonce' => 'x'];

    expect(capLoginScreenLogin()->verifyLogin(new WP_User, 'secret'))->toBeInstanceOf(WP_Error::class);
});

it('lets a wp-login.php login with a valid token through', function (): void {
    capPostTo('wp-login.php');
    $_POST = ['log' => 'admin', 'pwd' => 'secret', 'cap-token' => 'tok'];
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $user = new WP_User;

    expect(capLoginScreenLogin()->verifyLogin($user, 'secret'))->toBe($user);
});

it('leaves the WooCommerce My Account login to its own surface', function (): void {
    capPostTo('index.php');
    $_POST = ['login' => 'Log in', 'username' => 'admin', 'password' => 'secret', 'woocommerce-login-nonce' => 'x'];
    $user = new WP_User;

    expect(capLoginScreenLogin()->verifyLogin($user, 'secret'))->toBe($user);
});

it('skips a sign-in made by another page that names its button wp-submit', function (): void {
    // A theme or plugin form that never rendered our widget.
    capPostTo('index.php');
    $_POST = ['log' => 'admin', 'pwd' => 'secret', 'wp-submit' => 'Log In'];
    $user = new WP_User;

    expect(capLoginScreenLogin()->verifyLogin($user, 'secret'))->toBe($user);
});

it('verifies a wp-login.php registration that leaves out the submit button', function (): void {
    capPostTo('wp-login.php');
    $_POST = ['user_login' => 'bot', 'user_email' => 'bot@example.com'];

    $errors = capLoginScreenRegistration()->verifyRegistration(new WP_Error);

    expect($errors->get_error_codes())->toBe(['cap_captcha_failed']);
});

it('lets a wp-login.php registration with a valid token through', function (): void {
    capPostTo('wp-login.php');
    $_POST = ['user_login' => 'human', 'user_email' => 'human@example.com', 'cap-token' => 'tok'];
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    expect(capLoginScreenRegistration()->verifyRegistration(new WP_Error)->has_errors())->toBeFalse();
});

it('skips a registration made outside wp-login.php', function (): void {
    // register_new_user() called by another plugin's form or the REST API.
    capPostTo('index.php');
    $_POST = ['user_login' => 'human', 'user_email' => 'human@example.com', 'wp-submit' => 'Register'];

    expect(capLoginScreenRegistration()->verifyRegistration(new WP_Error)->has_errors())->toBeFalse();
});

// wp-signup.php writes the signup for any POST whose validation result carries
// no error; `stage` only picks which of its two steps runs.
it('refuses a multisite signup without a token', function (): void {
    capPostTo('wp-signup.php');
    $_POST = ['stage' => 'validate-user-signup', 'signup_for' => 'user', 'user_name' => 'bot', 'user_email' => 'bot@example.com'];

    $result = capLoginScreenRegistration()->verifySignup(['user_name' => 'bot', 'errors' => new WP_Error]);

    expect($result['errors']->get_error_codes())->toBe(['generic']);
});

it('refuses the site step of a multisite signup without a token', function (): void {
    capPostTo('wp-signup.php');
    $_POST = ['stage' => 'validate-blog-signup', 'user_name' => 'bot', 'user_email' => 'bot@example.com', 'blogname' => 'spam'];

    expect(capLoginScreenRegistration()->verifySignup(['errors' => new WP_Error])['errors']->has_errors())->toBeTrue();
});

it('lets a multisite signup with a valid token through', function (): void {
    capPostTo('wp-signup.php');
    $_POST = ['stage' => 'validate-user-signup', 'signup_for' => 'user', 'cap-token' => 'tok'];
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    expect(capLoginScreenRegistration()->verifySignup(['errors' => new WP_Error])['errors']->has_errors())->toBeFalse();
});

it('skips a user validated outside wp-signup.php', function (): void {
    // wp-admin/user-new.php and the REST users route run the same filter.
    capPostTo('user-new.php');

    expect(capLoginScreenRegistration()->verifySignup(['errors' => new WP_Error])['errors']->has_errors())->toBeFalse();
});
