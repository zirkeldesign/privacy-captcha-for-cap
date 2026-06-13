<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
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

    public function register(): void
    {
        add_action('woocommerce_after_checkout_billing_form', [$this, 'renderWidget']);
        add_filter('woocommerce_order_button_html', [$this, 'attachFloatingAttrsToSubmit']);
        add_action('woocommerce_checkout_process', [$this, 'verifyCheckout']);
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('WooCommerce checkout', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        // WC hooks woocommerce_order_button_html (below) so the existing
        // Place Order button becomes the floating trigger — no extra UI.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode(self::WIDGET_ID, $mode, false);
    }

    public function attachFloatingAttrsToSubmit(string $button): string
    {
        if (! $this->settings->isConfigured() || ! $this->settings->isFloating()) {
            return $button;
        }

        return Renderer::addFloatingAttrsToSubmit($button, self::WIDGET_ID);
    }

    public function verifyCheckout(): void
    {
        if (! $this->verifier->verifyCurrentRequest() && function_exists('wc_add_notice')) {
            wc_add_notice(
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap'),
                'error'
            );
        }
    }
}
