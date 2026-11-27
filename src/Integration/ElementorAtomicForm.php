<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * Protects the atomic form element of Elementor 4 (Elementor Pro). The element
 * has no field types to extend, so there is no widget: the visitor's browser
 * solves in the background when the form is sent, and every atomic form on the
 * site is checked. All submissions reach Elementor through one admin-ajax
 * action; the check runs in front of it, before Elementor stores an uploaded
 * file, and nothing in the request decides whether it runs.
 */
final class ElementorAtomicForm implements Integration
{
    private const AJAX_ACTION = 'elementor_pro_atomic_forms_send_form';

    public function __construct(
        private readonly Settings $settings,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'elementor_atomic';
    }

    /** Elementor fires the event the token travels on from 4.4; an older one would have every form refused. */
    public function isAvailable(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION') && defined('ELEMENTOR_VERSION') && version_compare((string) constant('ELEMENTOR_VERSION'), '4.4', '>=');
    }

    public function register(): void
    {
        // In front of Elementor's handler, which sits at the default priority.
        add_action('wp_ajax_'.self::AJAX_ACTION, [$this, 'verify'], 1);
        add_action('wp_ajax_nopriv_'.self::AJAX_ACTION, [$this, 'verify'], 1);
        add_action('elementor/frontend/e-form/before_render', [$this, 'enqueue']);
    }

    public function verify(): void
    {
        // An unconfigured plugin verifies nothing and must not refuse anything either.
        if (! $this->settings->isProtected($this->id()) || ! $this->settings->isConfigured()) {
            return;
        }

        if (! $this->verifier->verifyCurrentRequest($this->id())) {
            // The shape of Elementor's own error answers.
            wp_send_json_error(['message' => __('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap')]);
        }

        if ($this->verifier->wasLastFailOpen()) {
            do_action('cap_captcha_fail_open_pass', $this->id(), []);
        }
    }

    /** Elementor renders the element on every request: it is not held in the element cache. */
    public function enqueue(): void
    {
        if (! $this->solvesInBackground()) {
            return;
        }

        $this->enqueuer->enqueueCore();
        wp_enqueue_script('cap-captcha-elementor-atomic', CAP_CAPTCHA_URL.'assets/js/elementor-atomic-forms.js', [], CAP_CAPTCHA_VERSION, ['in_footer' => true]);

        // Not printGlobals(): nothing may be echoed while Elementor renders the element.
        $lines = '';
        foreach ($this->enqueuer->globals() + ['CAP_CAPTCHA_ATOMIC_FORM' => ['apiEndpoint' => $this->settings->getWidgetEndpoint()]] as $name => $value) {
            $lines .= sprintf('window.%s=%s;', $name, wp_json_encode($value));
        }

        wp_add_inline_script('cap-captcha-elementor-atomic', $lines, 'before');
    }

    /**
     * A site key that wants a press on the widget rejects a background solve:
     * no script is loaded then, and verify() refuses every submission.
     */
    private function solvesInBackground(): bool
    {
        return $this->settings->isProtected($this->id())
            && $this->settings->isConfigured()
            && ! $this->settings->isInlineForced();
    }
}
