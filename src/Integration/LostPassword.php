<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use WP_Error;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\RequestContext;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class LostPassword implements Integration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'lost_password';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function register(): void
    {
        add_action('lostpassword_form', [$this, 'renderWidget']);
        add_action('login_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('login_footer', [Login::class, 'printScriptModulesOnLogin']);
        add_action('lostpassword_post', [$this, 'verifyLostPassword'], 10, 1);
    }

    public function enqueueAssets(): void
    {
        if (! Login::isScreen('lostpassword') || ! $this->settings->isProtected('lost_password') || ! $this->settings->isConfigured()) {
            return;
        }

        $this->enqueuer->enqueueForMode($this->settings->getDisplayMode());
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isProtected('lost_password')) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('Lost password', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->printGlobals();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode('cap-captcha-lost-password', $mode);
    }

    public function verifyLostPassword(WP_Error $errors): void
    {
        if (! $this->settings->isProtected('lost_password')) {
            return;
        }

        // The WooCommerce My Account form posts to the same hook and is owned
        // by its own woocommerce_lost_password surface.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only detecting which form is posting; WooCommerce verifies this nonce itself before this runs.
        if (isset($_POST['woocommerce-lost-password-nonce'])) {
            return;
        }

        // retrieve_password() is also called from the users screen, REST and
        // plugins that never rendered our widget. `wp-submit` is wp-login.php's
        // own submit input.
        if (! RequestContext::isFormPost('wp-submit')) {
            return;
        }

        if ($this->verifier->verifyCurrentRequest('lost_password')) {
            return;
        }

        // Core adds "no account with that email" before this hook runs. Left in
        // place, a failed check would still tell existing addresses apart.
        foreach ($errors->get_error_codes() as $code) {
            $errors->remove($code);
        }

        $errors->add(
            'cap_captcha_failed',
            esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
        );
    }
}
