<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use WP_Error;
use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
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

    public function register(): void
    {
        add_action('register_form', [$this, 'renderWidget']);
        add_action('login_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('login_footer', [Login::class, 'printScriptModulesOnLogin']);
        add_filter('registration_errors', [$this, 'verifyRegistration']);
    }

    public function enqueueAssets(): void
    {
        if (! $this->settings->isConfigured()) {
            return;
        }
        $this->enqueuer->enqueueForMode($this->settings->getDisplayMode());
    }

    public function renderWidget(): void
    {
        if (! $this->settings->isConfigured()) {
            echo $this->renderer->renderAdminNotice(__('Registration', 'cap-captcha')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            return;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->printGlobals();

        echo $this->renderer->renderForMode('cap-captcha-register', $mode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function verifyRegistration(WP_Error $errors): WP_Error
    {
        if (! $this->verifier->verifyCurrentRequest()) {
            $errors->add(
                'cap_captcha_failed',
                esc_html__('CAPTCHA verification failed. Please try again.', 'cap-captcha')
            );
        }

        return $errors;
    }
}
