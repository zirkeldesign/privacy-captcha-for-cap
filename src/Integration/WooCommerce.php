<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;
use Exception;
use WP_Error;
use WP_REST_Request;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\RequestContext;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class WooCommerce implements Integration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'woocommerce';
    }

    public function isAvailable(): bool
    {
        return class_exists('WooCommerce');
    }

    public const WIDGET_ID = 'cap-captcha-checkout';

    public const LOGIN_WIDGET_ID = 'cap-captcha-wc-login';

    public const REGISTER_WIDGET_ID = 'cap-captcha-wc-register';

    public const LOST_PASSWORD_WIDGET_ID = 'cap-captcha-wc-lost-password';

    /**
     * Class on an empty span appended to the errors of a checkout that never
     * sent its token to Cap. The checkout script renews the token after every
     * other failed checkout, including ones that carry no notice at all.
     */
    public const KEPT_TOKEN_MARKER = 'cap-captcha-kept';

    private bool $lastCheckoutFailOpen = false;

    private bool $checkoutTokenKept = false;

    public function register(): void
    {
        // Checkout.
        add_action('woocommerce_after_checkout_billing_form', [$this, 'renderWidget']);
        add_filter('woocommerce_order_button_html', [$this, 'attachFloatingAttrsToSubmit']);
        // Last, so a plugin that removes a field error after us cannot let an
        // order through whose token was never checked.
        add_action('woocommerce_after_checkout_validation', [$this, 'verifyCheckout'], PHP_INT_MAX, 2);
        add_filter('woocommerce_add_error', [$this, 'markKeptTokenError']);
        add_action('woocommerce_checkout_create_order', [$this, 'refuseUncheckedOrder']);
        add_action('woocommerce_checkout_order_processed', [$this, 'annotateCheckoutFailOpen']);
        add_action('init', [$this, 'registerStoreApiTokenField']);
        add_filter('rest_request_before_callbacks', [$this, 'verifyStoreApiCheckout'], 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'annotateCheckoutFailOpen']);

        // My Account — login.
        add_action('woocommerce_login_form', [$this, 'renderLoginWidget']);
        add_filter('woocommerce_process_login_errors', [$this, 'verifyLogin'], 10, 1);

        // My Account — registration.
        add_action('woocommerce_register_form', [$this, 'renderRegisterWidget']);
        // Fires only from WooCommerce's My Account registration handler, not
        // for accounts created at checkout or by wc_create_new_customer().
        add_filter('woocommerce_process_registration_errors', [$this, 'verifyRegistration'], 10, 1);

        // My Account — lost password.
        add_action('woocommerce_lostpassword_form', [$this, 'renderLostPasswordWidget']);
        add_action('lostpassword_post', [$this, 'verifyLostPassword'], 10, 1);
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isProtected('woocommerce_checkout')) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('WooCommerce checkout', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        wp_enqueue_script(
            'cap-captcha-woocommerce-checkout',
            CAP_CAPTCHA_URL.'assets/js/woocommerce-checkout.js',
            ['jquery'],
            CAP_CAPTCHA_VERSION,
            ['in_footer' => true]
        );
        $this->enqueuer->printGlobals();

        // WC hooks woocommerce_order_button_html (below) so the existing
        // Place Order button becomes the floating trigger — no extra UI.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode(self::WIDGET_ID, $mode, false);
    }

    public function attachFloatingAttrsToSubmit(string $button): string
    {
        if (! $this->settings->isProtected('woocommerce_checkout') || ! $this->settings->isConfigured() || ! $this->settings->isFloating()) {
            return $button;
        }

        return Renderer::addFloatingAttrsToSubmit($button, self::WIDGET_ID);
    }

    /**
     * Runs once WooCommerce has validated the form. Cap tokens are single use,
     * so a form with errors keeps its token for the corrected submission.
     *
     * @param  array<string, mixed>  $data
     */
    public function verifyCheckout(array $data, WP_Error $errors): void
    {
        if (! $this->settings->isProtected('woocommerce_checkout')) {
            return;
        }

        // Gateways validate the form ahead of the order (PayPal Payments calls
        // validate_checkout() when its button is pressed). Only process_checkout()
        // places an order, and a token spent here would fail the real submit.
        if (did_action('woocommerce_checkout_process') === 0) {
            return;
        }

        // "Update totals" (no-JS checkout) validates the form but places no order.
        if (! empty($data['woocommerce_checkout_update_totals'])
            || $errors->has_errors()
            || (function_exists('wc_notice_count') && wc_notice_count('error') > 0)
        ) {
            $this->checkoutTokenKept = true;

            return;
        }

        if (! $this->verifier->verifyCurrentRequest('woocommerce_checkout')) {
            $errors->add(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
            );

            return;
        }

        $this->lastCheckoutFailOpen = $this->verifier->wasLastFailOpen();
    }

    /**
     * The Store API drops `extensions` data of a namespace without a schema.
     */
    public function registerStoreApiTokenField(): void
    {
        if (! function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'checkout',
            'namespace' => 'cap-captcha',
            'schema_callback' => static fn (): array => [
                'token' => [
                    'description' => __('Cap token proving the CAPTCHA was solved.', 'privacy-captcha-for-cap'),
                    'type' => 'string',
                    'context' => [],
                ],
            ],
        ]);
    }

    /**
     * The block checkout places its order through the Store API, where
     * woocommerce_checkout_process never fires. While checkout protection is on,
     * such an order needs a token in `extensions['cap-captcha']['token']`; the
     * block checkout renders no widget yet, so it is refused.
     *
     * @param  array<string, mixed>  $handler
     */
    public function verifyStoreApiCheckout(mixed $response, array $handler, WP_REST_Request $request): mixed
    {
        if ($response instanceof WP_Error || $request->get_method() !== 'POST') {
            return $response;
        }

        // Route matching is case-insensitive, and the Store API also answers
        // under the unversioned `wc/store` namespace.
        if (preg_match('#^/wc/store(?:/v\d+)?/checkout/?$#i', $request->get_route()) !== 1) {
            return $response;
        }

        if (! $this->settings->isProtected('woocommerce_checkout') || ! $this->protectsStoreApiCheckout($request)) {
            return $response;
        }

        $extensions = $request->get_param('extensions');
        $token = is_array($extensions) && is_string($extensions['cap-captcha']['token'] ?? null)
            ? sanitize_text_field($extensions['cap-captcha']['token'])
            : '';

        $verified = $this->verifier->verifyToken($token, 'woocommerce_checkout');

        // A batch request runs several checkouts in one PHP request; each one
        // must redeem its own token rather than reuse the cached result.
        $this->verifier->forget($token);

        if (! $verified) {
            return new WP_Error(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap'),
                ['status' => 400]
            );
        }

        $this->lastCheckoutFailOpen = $this->verifier->wasLastFailOpen();

        return $response;
    }

    /**
     * The block checkout and the express buttons of some gateways place their
     * orders through the Store API but render no widget, so enforcing there
     * would refuse every such order. Until the block checkout has a widget,
     * the Store API is checked except on block-checkout shops and for orders
     * paid with a gateway whose express buttons are on; an order naming that
     * gateway still has to pass the gateway's own payment.
     */
    private function protectsStoreApiCheckout(WP_REST_Request $request): bool
    {
        $paymentMethod = $request->get_param('payment_method');
        $exempt = $this->usesCheckoutBlock()
            || (is_string($paymentMethod) && in_array($paymentMethod, $this->storeApiExpressGateways(), true));

        /**
         * Whether this Store API checkout must carry a Cap token.
         *
         * @param  bool  $protect  False on block-checkout shops and for express-button gateways.
         * @param  WP_REST_Request  $request
         */
        return (bool) apply_filters('cap_captcha_protect_store_api_checkout', ! $exempt, $request);
    }

    private function usesCheckoutBlock(): bool
    {
        if (class_exists(CartCheckoutUtils::class)) {
            return CartCheckoutUtils::is_checkout_block_default();
        }

        return function_exists('wc_get_page_id') && has_block('woocommerce/checkout', wc_get_page_id('checkout'));
    }

    /**
     * Ids of enabled gateways whose express buttons (Apple Pay, Google Pay,
     * Link) order through the Store API checkout, also on the classic
     * checkout: Stripe and WooPayments.
     *
     * @return array<int, string>
     */
    private function storeApiExpressGateways(): array
    {
        if (! function_exists('WC') || ! is_object(WC()->payment_gateways())) {
            return [];
        }

        $ids = [];

        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            if (! is_object($gateway) || ($gateway->enabled ?? 'no') !== 'yes' || ! is_string($gateway->id ?? null)) {
                continue;
            }

            // Stripe deprecated is_payment_request_enabled() for this one.
            $method = method_exists($gateway, 'is_express_checkout_enabled') ? 'is_express_checkout_enabled' : 'is_payment_request_enabled';

            try {
                if (method_exists($gateway, $method) && $gateway->{$method}()) {
                    $ids[] = $gateway->id;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $ids;
    }

    /**
     * The token is skipped only because the form had errors, so WooCommerce
     * should place no order. An error that never became a notice (an empty
     * message, or a filter that blanked it) would let one through unchecked.
     *
     * @throws Exception
     */
    public function refuseUncheckedOrder(): void
    {
        if (! $this->checkoutTokenKept) {
            return;
        }

        throw new Exception(esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap'));
    }

    /**
     * WooCommerce passes the error HTML to the checkout's `checkout_error`
     * event. A form rejected by its own validation never reached Cap and keeps
     * its token; every other failure may have spent it.
     */
    public function markKeptTokenError(mixed $message): mixed
    {
        if (! $this->checkoutTokenKept || ! is_string($message) || str_contains($message, self::KEPT_TOKEN_MARKER)) {
            return $message;
        }

        return $message.'<span class="'.self::KEPT_TOKEN_MARKER.'"></span>';
    }

    /**
     * Tag an order placed during a Cap outage (fail-open) with order meta and a
     * note, so it can be reviewed later.
     *
     * @param  int|string|object  $orderId  The order id, or the order itself from the Store API.
     */
    public function annotateCheckoutFailOpen($orderId): void
    {
        if (! $this->lastCheckoutFailOpen) {
            return;
        }

        $this->lastCheckoutFailOpen = false;

        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (is_object($order) && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('cap_captcha_fail_open', 1);
            $order->add_order_note(esc_html__('Placed during a Cap outage — CAPTCHA verification was skipped (fail-open).', 'privacy-captcha-for-cap'));
            $order->save();
        }

        do_action('cap_captcha_fail_open_pass', 'woocommerce_checkout', ['order_id' => is_object($orderId) && method_exists($orderId, 'get_id') ? (int) $orderId->get_id() : (int) $orderId]);
    }

    public function renderLoginWidget(): void
    {
        $this->renderAccountWidget('woocommerce_login', self::LOGIN_WIDGET_ID, __('WooCommerce login', 'privacy-captcha-for-cap'));
    }

    public function renderRegisterWidget(): void
    {
        $this->renderAccountWidget('woocommerce_registration', self::REGISTER_WIDGET_ID, __('WooCommerce registration', 'privacy-captcha-for-cap'));
    }

    public function renderLostPasswordWidget(): void
    {
        $this->renderAccountWidget('woocommerce_lost_password', self::LOST_PASSWORD_WIDGET_ID, __('WooCommerce lost password', 'privacy-captcha-for-cap'));
    }

    public function verifyLogin(WP_Error $errors): WP_Error
    {
        return $this->verifyAccountForm('woocommerce_login', $errors);
    }

    public function verifyRegistration(WP_Error $errors): WP_Error
    {
        return $this->verifyAccountForm('woocommerce_registration', $errors);
    }

    public function verifyLostPassword(WP_Error $errors): void
    {
        // lostpassword_post also fires on wp-login.php (the lost_password
        // surface) and for resets started from the users screen or REST.
        // WooCommerce's handler does nothing without `wc_reset_password`; its
        // nonce is no marker, it is also accepted as `_wpnonce` or in the URL.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detecting WooCommerce's handler; it verifies its own nonce before this runs.
        if (RequestContext::isLoginScreenPost() || ! isset($_POST['wc_reset_password'])) {
            return;
        }

        $this->verifyAccountForm('woocommerce_lost_password', $errors);
    }

    private function renderAccountWidget(string $context, string $widgetId, string $surfaceLabel): void
    {
        if (! $this->settings->isProtected($context)) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice($surfaceLabel), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode($widgetId, $mode);
    }

    private function verifyAccountForm(string $context, WP_Error $errors): WP_Error
    {
        if (! $this->settings->isProtected($context)) {
            return $errors;
        }

        if (! $this->verifier->verifyCurrentRequest($context)) {
            $errors->add(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
            );
        }

        return $errors;
    }
}
