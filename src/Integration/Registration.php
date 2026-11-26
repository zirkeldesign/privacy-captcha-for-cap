<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use WP_Error;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\RequestContext;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class Registration implements Integration
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'registration';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    private bool $lastFailOpen = false;

    public function register(): void
    {
        add_action('register_form', [$this, 'renderWidget']);
        add_action('login_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('login_footer', [Login::class, 'printScriptModulesOnLogin']);
        add_filter('registration_errors', [$this, 'verifyRegistration']);
        add_action('user_register', [$this, 'annotateFailOpen']);

        // A multisite network registers through wp-signup.php, which never
        // applies registration_errors. Both of its forms validate the account.
        add_action('signup_extra_fields', [$this, 'renderSignupWidget']);
        add_action('signup_blogform', [$this, 'renderSignupWidget']);
        add_filter('wpmu_validate_user_signup', [$this, 'verifySignup']);
    }

    public function enqueueAssets(): void
    {
        if (! Login::isScreen('register') || ! $this->settings->isProtected('registration') || ! $this->settings->isConfigured()) {
            return;
        }
        $this->enqueuer->enqueueForMode($this->settings->getDisplayMode());
    }

    public function renderWidget(): void
    {
        $this->printWidget('cap-captcha-register');
    }

    public function renderSignupWidget(): void
    {
        // A signed-in user adding another site registers no account, and
        // wpmu_validate_user_signup never runs for that form.
        if (is_user_logged_in()) {
            return;
        }

        // wp-signup.php is a front-end page: wp_footer prints what is enqueued here.
        if ($this->settings->isProtected('registration') && $this->settings->isConfigured()) {
            $this->enqueuer->enqueueForMode($this->settings->getDisplayMode());
        }

        $this->printWidget('cap-captcha-signup');
    }

    private function printWidget(string $widgetId): void
    {
        if (! $this->settings->isProtected('registration')) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('Registration', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->printGlobals();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer returns a <cap-widget> custom element with dynamic data-cap-i18n-* attributes wp_kses cannot allowlist; every interpolated value is escaped via esc_attr()/esc_html() inside Renderer.
        echo $this->renderer->renderForMode($widgetId, $mode);
    }

    public function verifyRegistration(WP_Error $errors): WP_Error
    {
        if (! $this->settings->isProtected('registration')) {
            return $errors;
        }

        // register_new_user() is also reachable from REST, XML-RPC and CLI, none
        // of which rendered our widget.
        if (! RequestContext::isLoginScreenPost()) {
            return $errors;
        }

        if (! $this->verifier->verifyCurrentRequest('registration')) {
            $errors->add(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
            );

            return $errors;
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();

        return $errors;
    }

    /**
     * wp-signup.php validates the account on each of its two steps and stores
     * the signup when the result carries no error. The same filter runs for
     * users added in wp-admin and over REST, which show no widget.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function verifySignup(array $result): array
    {
        if (! $this->settings->isProtected('registration') || ! RequestContext::isSignupScreenPost()) {
            return $result;
        }

        // shortcut: no fail-open mark, the account is only created on activation in a later request; carry it in the signup meta if that review is needed.
        if ($this->verifier->verifyCurrentRequest('registration')) {
            return $result;
        }

        if (! ($result['errors'] ?? null) instanceof WP_Error) {
            $result['errors'] = new WP_Error;
        }

        // 'generic' is the one code the signup form prints without a field.
        $result['errors']->add(
            'generic',
            esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')
        );

        return $result;
    }

    /**
     * Tag a user created through a registration that only passed because Cap
     * was unreachable.
     *
     * @param  int|string  $userId
     */
    public function annotateFailOpen($userId): void
    {
        if (! $this->lastFailOpen) {
            return;
        }

        $this->lastFailOpen = false;
        add_user_meta((int) $userId, 'cap_captcha_fail_open', 1, true);
        do_action('cap_captcha_fail_open_pass', 'registration', ['user_id' => (int) $userId]);
    }
}
