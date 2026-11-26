<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

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

    public const ORDER_PAY_WIDGET_ID = 'cap-captcha-order-pay';

    public const BLOCK_WIDGET_ID = 'cap-captcha-checkout-block';

    private const PASS_SESSION_KEY = 'cap_captcha_pass';

    private const PASS_TRANSIENT = 'cap_captcha_pass_';

    /**
     * Seconds a pass stays valid: long enough for a wallet sheet, short
     * enough that a solved challenge cannot be banked.
     */
    private const PASS_TTL = 600;

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

        // Checkout block: the widget sits above "Place order", its token
        // travels in the request's `extensions`.
        add_filter('render_block_woocommerce/checkout-actions-block', [$this, 'renderBlockWidget']);

        // Express buttons build their Store API request in the gateway's own
        // script, which carries no token. A solved challenge is banked as a
        // one-time pass in the session instead.
        add_action('wp_enqueue_scripts', [$this, 'enqueueExpressPass']);
        add_action('wc_ajax_cap_captcha_pass', [$this, 'issuePass']);

        // Paying for an order that exists: each attempt is a payment of its
        // own, whoever placed the order.
        add_action('woocommerce_pay_order_before_submit', [$this, 'renderOrderPayWidget']);
        add_action('woocommerce_before_pay_action', [$this, 'verifyOrderPay']);

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

    public function renderOrderPayWidget(): void
    {
        $this->renderAccountWidget('woocommerce_checkout', self::ORDER_PAY_WIDGET_ID, __('WooCommerce checkout', 'privacy-captcha-for-cap'));
    }

    /**
     * WC_Form_Handler::pay_action() goes on to the gateway's process_payment()
     * unless an error notice exists by then. A notice can be blanked by a
     * filter, so a refused payment ends the request here.
     */
    public function verifyOrderPay(mixed $order): void
    {
        if (! $this->settings->isProtected('woocommerce_checkout') || $this->verifier->verifyCurrentRequest('woocommerce_checkout')) {
            return;
        }

        // A visitor without a session cookie would lose the notice on the redirect.
        $session = function_exists('WC') ? (WC()->session ?? null) : null;
        if (is_object($session) && method_exists($session, 'has_session') && ! $session->has_session()) {
            $session->set_customer_session_cookie(true);
        }

        if (function_exists('wc_add_notice')) {
            wc_add_notice(esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap'), 'error');
        }

        wp_safe_redirect(is_object($order) && method_exists($order, 'get_checkout_payment_url') ? $order->get_checkout_payment_url() : home_url('/'));
        exit;
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
     * The block checkout and the express buttons of some gateways place their
     * orders through the Store API, where woocommerce_checkout_process never
     * fires. While checkout protection is on, such an order needs a token in
     * `extensions['cap-captcha']['token']` or a pass in the session.
     *
     * @param  array<string, mixed>  $handler
     */
    public function verifyStoreApiCheckout(mixed $response, array $handler, WP_REST_Request $request): mixed
    {
        if ($response instanceof WP_Error || $request->get_method() !== 'POST') {
            return $response;
        }

        // Route matching is case-insensitive, and the Store API also answers
        // under the unversioned `wc/store` namespace. `checkout/<id>` pays for
        // an order that exists.
        if (preg_match('#^/wc/store(?:/v\d+)?/checkout(?:/\d+)?/?$#i', $request->get_route()) !== 1) {
            return $response;
        }

        if (! $this->settings->isProtected('woocommerce_checkout') || ! $this->protectsStoreApiCheckout($request)) {
            return $response;
        }

        $extensions = $request->get_param('extensions');
        $token = is_array($extensions) && is_string($extensions['cap-captcha']['token'] ?? null)
            ? sanitize_text_field($extensions['cap-captcha']['token'])
            : '';

        if ($token === '' && $this->settings->isConfigured()) {
            $pass = $this->redeemPass();

            if ($pass !== null) {
                $this->lastCheckoutFailOpen = $pass;

                return $response;
            }
        }

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

    private function protectsStoreApiCheckout(WP_REST_Request $request): bool
    {
        /**
         * Whether this Store API checkout must carry a Cap token or redeem a
         * pass. For shops whose own front end orders through the Store API.
         *
         * @param  bool  $protect
         * @param  WP_REST_Request  $request
         */
        return (bool) apply_filters('cap_captcha_protect_store_api_checkout', true, $request);
    }

    /**
     * The widget for the Checkout block. WooCommerce keeps markup it finds
     * next to the inner block when it builds the form in the browser.
     */
    public function renderBlockWidget(mixed $content): mixed
    {
        if (! is_string($content) || ! $this->settings->isProtected('woocommerce_checkout') || ! $this->settings->isConfigured()) {
            return $content;
        }

        $inline = $this->settings->getDisplayMode() === Settings::MODE_INLINE;

        // The button belongs to the block's script, so nothing can be attached
        // to it: floating solves in the background here, like programmatic.
        $this->enqueuer->enqueueCore();
        $this->enqueueStoreScript('cap-captcha-checkout-block', 'woocommerce-checkout-block.js', ['wp-data'], 'CAP_CAPTCHA_CHECKOUT_BLOCK', [
            'apiEndpoint' => $this->settings->getWidgetEndpoint(),
            'widgetId' => $inline ? self::BLOCK_WIDGET_ID : '',
        ]);

        return $inline ? $this->renderer->renderForMode(self::BLOCK_WIDGET_ID, Settings::MODE_INLINE).$content : $content;
    }

    /**
     * Loads the script that banks a pass on pages where a gateway shows
     * express buttons: product, cart, classic checkout and order-pay.
     */
    public function enqueueExpressPass(): void
    {
        if (! $this->settings->isProtected('woocommerce_checkout') || ! $this->settings->isConfigured()) {
            return;
        }

        // A site key that wants a press on the widget rejects a background
        // solve, so no pass can be earned and express orders are refused.
        if ($this->settings->isInlineForced() || $this->storeApiExpressGateways() === []) {
            return;
        }

        $onShopPage = (function_exists('is_product') && is_product())
            || (function_exists('is_cart') && is_cart())
            || (function_exists('is_checkout') && is_checkout());
        $payingOrder = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');

        // The Checkout block sends the widget's token with its express orders.
        if (! $onShopPage || (! $payingOrder && function_exists('is_checkout') && is_checkout() && has_block('woocommerce/checkout'))) {
            return;
        }

        $this->enqueuer->enqueueCore();
        $this->enqueueStoreScript('cap-captcha-express-pass', 'woocommerce-express-pass.js', [], 'CAP_CAPTCHA_EXPRESS_PASS', [
            'apiEndpoint' => $this->settings->getWidgetEndpoint(),
            'url' => class_exists('WC_AJAX') ? \WC_AJAX::get_endpoint('cap_captcha_pass') : '',
            'ttl' => self::PASS_TTL,
        ]);
    }

    /**
     * @param  array<int, string>  $deps
     * @param  array<string, mixed>  $config
     */
    private function enqueueStoreScript(string $handle, string $file, array $deps, string $global, array $config): void
    {
        wp_enqueue_script($handle, CAP_CAPTCHA_URL.'assets/js/'.$file, $deps, CAP_CAPTCHA_VERSION, ['in_footer' => true]);

        // Not printGlobals(): nothing may be echoed while a block renders.
        $lines = '';
        foreach ($this->enqueuer->globals() + [$global => $config] as $name => $value) {
            $lines .= sprintf('window.%s=%s;', $name, wp_json_encode($value));
        }

        wp_add_inline_script($handle, $lines, 'before');
    }

    /**
     * Trades a solved challenge for a pass: one Store API checkout attempt by
     * this session. The pass is a transient under a random id that only the
     * session knows, so no field of the later request can claim it.
     */
    public function issuePass(): void
    {
        $session = function_exists('WC') ? (WC()->session ?? null) : null;

        if (! is_object($session)
            || ! $this->settings->isProtected('woocommerce_checkout')
            || ! $this->settings->isConfigured()
            || ! $this->verifier->verifyCurrentRequest('woocommerce_checkout')
        ) {
            wp_send_json_error(null, 400);
        }

        if (method_exists($session, 'has_session') && ! $session->has_session()) {
            $session->set_customer_session_cookie(true);
        }

        $id = wp_generate_password(32, false);
        set_transient(self::PASS_TRANSIENT.$id, ['fail_open' => $this->verifier->wasLastFailOpen()], self::PASS_TTL);
        $session->set(self::PASS_SESSION_KEY, $id);

        wp_send_json_success();
    }

    /**
     * Spends the session's pass. Null when there is none, otherwise whether
     * it was earned through fail-open.
     */
    private function redeemPass(): ?bool
    {
        if (! function_exists('WC')) {
            return null;
        }

        // The Store API loads the session only inside its route callback.
        if (! is_object(WC()->session ?? null) && method_exists(WC(), 'initialize_session')) {
            WC()->initialize_session();
        }

        $session = WC()->session ?? null;
        $id = is_object($session) ? $session->get(self::PASS_SESSION_KEY) : null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $session->set(self::PASS_SESSION_KEY, null);
        $pass = get_transient(self::PASS_TRANSIENT.$id);

        // Of two requests that arrive together, only the one whose delete
        // removed the record holds the pass.
        if (! is_array($pass) || ! delete_transient(self::PASS_TRANSIENT.$id)) {
            return null;
        }

        return ! empty($pass['fail_open']);
    }

    /**
     * Ids of enabled gateways whose express buttons (Apple Pay, Google Pay,
     * Link) order through the Store API checkout, also on classic pages:
     * Stripe and WooPayments. Decides where the pass script loads, never
     * whether a request is checked.
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
