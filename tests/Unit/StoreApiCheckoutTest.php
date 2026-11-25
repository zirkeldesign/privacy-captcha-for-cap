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
    $GLOBALS['__cap_page_blocks'] = [];
    $GLOBALS['__cap_wc_gateways'] = [];
    unset($GLOBALS['__cap_wc'], $GLOBALS['__cap_wc_session']);
    cap_reset_transients();
    capWcSession();
});

if (! function_exists('WC')) {
    function WC(): object
    {
        return $GLOBALS['__cap_wc'] ??= new class
        {
            public ?object $session = null;

            public function payment_gateways(): object
            {
                return new class
                {
                    /** @return array<int, object> */
                    public function payment_gateways(): array
                    {
                        return $GLOBALS['__cap_wc_gateways'] ?? [];
                    }
                };
            }

            // The Store API has not loaded the session yet when the check runs.
            public function initialize_session(): void
            {
                $this->session = $GLOBALS['__cap_wc_session'];
            }
        };
    }
}

/** The visitor's WooCommerce session, as its cookie would bring it back. */
function capWcSession(): object
{
    return $GLOBALS['__cap_wc_session'] ??= new class
    {
        /** @var array<string, mixed> */
        public array $data = [];

        public bool $cookie = false;

        public function get(string $key): mixed
        {
            return $this->data[$key] ?? null;
        }

        public function set(string $key, mixed $value): void
        {
            $this->data[$key] = $value;
        }

        public function has_session(): bool
        {
            return $this->cookie;
        }

        public function set_customer_session_cookie(bool $set): void
        {
            $this->cookie = $set;
        }
    };
}

/** Solve once and bank the pass, as the express-pass script does. */
function capIssuePass(WooCommerce $woo, string $token = 'tok'): string
{
    WC()->session = capWcSession();
    $_POST = ['cap-token' => $token];

    try {
        $woo->issuePass();
    } catch (CapTestHalt $halt) {
        $_POST = [];
        WC()->session = null;

        return $halt->getMessage();
    }

    return 'no answer';
}

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

function capStoreApiRequest(string $method = 'POST', string $route = '/wc/store/v1/checkout', string $token = '', string $paymentMethod = 'cod'): WP_REST_Request
{
    $request = new WP_REST_Request($method, $route);
    $request->set_param('payment_method', $paymentMethod);
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

it('checks the Store API on a shop with the Checkout block', function (): void {
    $GLOBALS['__cap_page_blocks'] = ['woocommerce/checkout'];

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest()))->toBeInstanceOf(WP_Error::class);
});

// 1.8.2 waived the check for a request that named an express gateway. The
// sender picks payment_method, so it decides nothing any more.
it('checks an order that names an express-button gateway', function (object $gateway): void {
    $GLOBALS['__cap_wc_gateways'] = [$gateway];

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest(paymentMethod: $gateway->id)))->toBeInstanceOf(WP_Error::class);
})->with([
    'Stripe' => [new class
    {
        public string $id = 'stripe';

        public string $enabled = 'yes';

        public function is_express_checkout_enabled(): bool
        {
            return true;
        }
    }],
    'WooPayments' => [new class
    {
        public string $id = 'woocommerce_payments';

        public string $enabled = 'yes';

        public function is_payment_request_enabled(): bool
        {
            return true;
        }
    }],
]);

it('lets a tokenless Store API checkout spend the pass of its session, once', function (string $route): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $woo = capStoreApiWoo();

    expect(capIssuePass($woo))->toBe('success')
        ->and(capWcSession()->cookie)->toBeTrue()
        ->and($woo->verifyStoreApiCheckout(null, [], capStoreApiRequest('POST', $route)))->toBeNull()
        ->and($woo->verifyStoreApiCheckout(null, [], capStoreApiRequest('POST', $route)))->toBeInstanceOf(WP_Error::class);
})->with(['/wc/store/v1/checkout', '/wc/store/v1/checkout/42']);

it('issues no pass for a token Cap rejects, or for none', function (string $token): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $woo = capStoreApiWoo();

    expect(capIssuePass($woo, $token))->toBe('wp_send_json_error')
        ->and($woo->verifyStoreApiCheckout(null, [], capStoreApiRequest()))->toBeInstanceOf(WP_Error::class);
})->with(['bad-token', '']);

it('refuses a pass id the session holds but the server never issued', function (): void {
    capWcSession()->set('cap_captcha_pass', 'made-up');

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest()))->toBeInstanceOf(WP_Error::class);
});

it('gives a pass to one of two requests that read the session together', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $woo = capStoreApiWoo();
    capIssuePass($woo);
    $id = capWcSession()->get('cap_captcha_pass');

    expect($woo->verifyStoreApiCheckout(null, [], capStoreApiRequest()))->toBeNull();

    // The second request loaded its session before the first one saved.
    WC()->session = null;
    capWcSession()->set('cap_captcha_pass', $id);

    expect($woo->verifyStoreApiCheckout(null, [], capStoreApiRequest()))->toBeInstanceOf(WP_Error::class);
});

it('marks an order whose pass was earned through fail-open', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'timeout');
    $woo = capStoreApiWoo(failOpen: true);
    capIssuePass($woo);
    $GLOBALS['__cap_actions'] = [];

    $woo->verifyStoreApiCheckout(null, [], capStoreApiRequest());
    $woo->annotateCheckoutFailOpen(7);

    expect($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'][0][1]['order_id'] ?? null)->toBe(7);
});

it('puts the inline widget above the Checkout block\'s button', function (): void {
    $html = capStoreApiWoo()->renderBlockWidget('<div data-block-name="woocommerce/checkout-actions-block"></div>');

    expect($html)->toContain('<cap-widget id="cap-captcha-checkout-block"')
        ->and($html)->toEndWith('<div data-block-name="woocommerce/checkout-actions-block"></div>');
});

it('leaves the Checkout block alone while checkout protection is off', function (): void {
    expect(capStoreApiWoo(checkout: false)->renderBlockWidget('<div></div>'))->toBe('<div></div>');
});

it('still checks the Store API when the express gateway is switched off', function (): void {
    $GLOBALS['__cap_wc_gateways'] = [new class
    {
        public string $id = 'stripe';

        public string $enabled = 'no';

        public function is_express_checkout_enabled(): bool
        {
            return true;
        }
    }];

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest(paymentMethod: 'stripe')))->toBeInstanceOf(WP_Error::class);
});

it('checks the Store API when a gateway fails to say whether express is on', function (): void {
    $GLOBALS['__cap_wc_gateways'] = [new class
    {
        public string $id = 'stripe';

        public string $enabled = 'yes';

        public function is_express_checkout_enabled(): bool
        {
            throw new RuntimeException('no API connection');
        }
    }];

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest(paymentMethod: 'stripe')))->toBeInstanceOf(WP_Error::class);
});

it('lets a filter take the Store API check off', function (): void {
    add_filter('cap_captcha_protect_store_api_checkout', fn (): bool => false);

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest()))->toBeNull();
});

it('asks Cap again for every checkout in one batch request', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $woo = capStoreApiWoo();

    $woo->verifyStoreApiCheckout(null, [], capStoreApiRequest(token: 'tok'));
    $woo->verifyStoreApiCheckout(null, [], capStoreApiRequest(token: 'tok'));

    expect($GLOBALS['__cap_remote_post_count'])->toBe(2);
});

it('passes the order id of a Store API order to the fail-open action', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('http_request_failed', 'down');
    $woo = capStoreApiWoo(failOpen: true);
    $GLOBALS['__cap_actions'] = [];
    $woo->verifyStoreApiCheckout(null, [], capStoreApiRequest(token: 'tok'));

    $woo->annotateCheckoutFailOpen(new class
    {
        public function get_id(): int
        {
            return 1234;
        }
    });

    expect($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'][0][1]['order_id'] ?? null)->toBe(1234);
});

// Paying for an order that exists. A guest needs the order key and billing
// email, both of which whoever placed the order holds.
it('refuses a Store API payment for an existing order without a token', function (string $route): void {
    $result = capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest('POST', $route));

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->get_error_code())->toBe('cap_captcha_failed');
})->with(['/wc/store/v1/checkout/42', '/wc/store/checkout/42/', '/WC/Store/V1/Checkout/42']);

it('lets a Store API payment for an existing order with a valid token through', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    expect(capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest('POST', '/wc/store/v1/checkout/42', 'tok')))->toBeNull();
});

it('checks a Store API payment for an existing order on a block-checkout shop and for an express gateway too', function (): void {
    $GLOBALS['__cap_page_blocks'] = ['woocommerce/checkout'];
    $GLOBALS['__cap_wc_gateways'] = [new class
    {
        public string $id = 'stripe';

        public string $enabled = 'yes';

        public function is_express_checkout_enabled(): bool
        {
            return true;
        }
    }];

    $result = capStoreApiWoo()->verifyStoreApiCheckout(null, [], capStoreApiRequest('POST', '/wc/store/v1/checkout/42', '', 'stripe'));

    expect($result)->toBeInstanceOf(WP_Error::class);
});

// WC_Form_Handler::pay_action() needs `woocommerce_pay`, the order key and the
// pay nonce, all of which the order-pay page hands to whoever opens it.
it('ends an order-pay request without a token before the gateway is asked', function (): void {
    $GLOBALS['__cap_wc_notices'] = [];
    $_POST = ['woocommerce_pay' => '1', 'payment_method' => 'stripe'];

    expect(fn () => capStoreApiWoo()->verifyOrderPay(null))->toThrow(CapTestHalt::class, 'redirect:');
    expect($GLOBALS['__cap_wc_notices']['error'] ?? [])->toHaveCount(1);
});

it('lets an order-pay request with a valid token through', function (): void {
    $_POST = ['woocommerce_pay' => '1', 'payment_method' => 'stripe', 'cap-token' => 'tok'];
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    capStoreApiWoo()->verifyOrderPay(null);

    expect(true)->toBeTrue();
});

it('leaves order-pay alone while checkout protection is off', function (): void {
    capStoreApiWoo(checkout: false)->verifyOrderPay(null);

    expect(true)->toBeTrue();
});
