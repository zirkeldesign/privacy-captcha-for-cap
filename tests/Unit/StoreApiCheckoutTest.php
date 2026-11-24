<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\WooCommerce;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    $_POST = [];
    cap_reset_options();
    cap_reset_remote_stub();
    cap_reset_filters();
});

function capStoreApiWoo(bool $checkout = true, bool $failOpen = false): WooCommerce
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'fail_open' => $failOpen,
        'integrations' => ['woocommerce' => true, 'woocommerce_checkout' => $checkout],
    ]);
    $settings = new Settings;

    return new WooCommerce($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

function capStoreApiRequest(string $method = 'POST', string $route = '/wc/store/v1/checkout', string $token = ''): WP_REST_Request
{
    $request = new WP_REST_Request($method, $route);
    if ($token !== '') {
        $request->set_param('extensions', ['cap-captcha' => ['token' => $token]]);
    }

    return $request;
}

it('refuses a Store API checkout without a token', function (string $route): void {
    $result = capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest('POST', $route));

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('cap_captcha_failed');
})->with(['/wc/store/v1/checkout', '/wc/store/checkout', '/WC/Store/V1/Checkout/']);

it('refuses a Store API checkout whose token Cap rejects', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];

    $result = capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest(token: 'tok'));

    expect($result)->toBeInstanceOf(WP_Error::class);
});

it('lets a Store API checkout with a valid token through', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest(token: 'tok')))->toBeNull();
});

it('lets a Store API checkout through while Cap is unreachable and fail-open is on', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'timeout');

    expect(capStoreApiWoo(failOpen: true)->verifyStoreApiCheckout(null, [], capStoreApiRequest(token: 'tok')))->toBeNull();
});

it('leaves other Store API requests alone', function (string $method, string $route): void {
    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest($method, $route)))->toBeNull();
})->with([
    'checkout field update' => ['PUT', '/wc/store/v1/checkout'],
    'cart' => ['POST', '/wc/store/v1/cart/add-item'],
    'other namespace' => ['POST', '/wp/v2/posts'],
]);

it('leaves the Store API checkout alone while checkout protection is off', function (): void {
    expect(capStoreApiWoo(checkout: false)->verifyStoreApiCheckout(null, [], capStoreApiRequest()))->toBeNull();
});

it('passes on an earlier error unchanged', function (): void {
    $earlier = new WP_Error('rest_invalid_param', 'x');

    expect(capStoreApiWoo()->verifyStoreApiCheckout($earlier, [], capStoreApiRequest()))->toBe($earlier);
});

it('registers the token field in the checkout endpoint data, so the Store API keeps it', function (): void {
    if (! function_exists('woocommerce_store_api_register_endpoint_data')) {
        function woocommerce_store_api_register_endpoint_data(array $args): bool
        {
            $GLOBALS['__cap_store_api_endpoint_data'][] = $args;

            return true;
        }
    }
    $GLOBALS['__cap_store_api_endpoint_data'] = [];

    capStoreApiWoo()->registerStoreApiTokenField();

    $args = $GLOBALS['__cap_store_api_endpoint_data'][0];
    expect($args['endpoint'])->toBe('checkout')
        ->and($args['namespace'])->toBe('cap-captcha')
        ->and(($args['schema_callback'])()['token']['type'])->toBe('string');
});
