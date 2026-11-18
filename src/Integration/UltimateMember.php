<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * Protects the Ultimate Member login, registration and password reset forms.
 * UM posts these to the page they sit on and validates them through its own
 * error hooks, so none of the core wp-login hooks the Login and Registration
 * integrations use are involved.
 */
final class UltimateMember implements Integration
{
    private const ERROR_KEY = 'cap-token';

    /** The submit button UM prints on `um_after_{login,register}_fields` at this priority. */
    private const SUBMIT_PRIORITY = 1000;

    /** The form whose submission failed the check: its UM form id, or 'password' for the reset form. */
    private int|string|null $failedForm = null;

    private bool $lastFailOpen = false;

    private int|string|null $renderingForm = null;

    /** Widget id waiting for the submit button UM is about to print, in floating mode. */
    private ?string $pendingFloating = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'ultimate_member';
    }

    public function isAvailable(): bool
    {
        return defined('UM_VERSION') && function_exists('UM');
    }

    public function register(): void
    {
        foreach (['um_after_login_fields', 'um_after_register_fields'] as $hook) {
            add_action($hook, [$this, 'renderWidget'], self::SUBMIT_PRIORITY - 1, 2);
            add_action($hook, [$this, 'wireSubmitButton'], self::SUBMIT_PRIORITY + 1, 0);
        }
        add_action('um_after_password_reset_fields', [$this, 'renderResetWidget'], 10, 0);

        // Before UM's own handlers at 10, so a failed check can keep the password from being tested.
        add_action('um_submit_form_errors_hook_login', [$this, 'validateLogin'], 5, 1);
        add_action('um_submit_form_errors_hook__registration', [$this, 'validateRegistration'], 5, 1);
        add_action('um_reset_password_errors_hook', [$this, 'validateReset'], 5, 1);

        add_filter('um_before_save_filter_submitted', [$this, 'removeToken']);
        add_action('user_register', [$this, 'annotateFailOpen']);
    }

    /**
     * Renders just above the submit button UM prints at priority 1000. In
     * floating mode that button becomes the trigger, so its output is buffered
     * until wireSubmitButton() runs right after it.
     */
    public function renderWidget(mixed $args = [], mixed $formId = 0): void
    {
        $mode = $this->prepare();
        if ($mode === null) {
            return;
        }

        $this->renderingForm = (int) $formId;
        if ($mode !== Settings::MODE_FLOATING) {
            $this->printWidget($this->renderer->renderForMode(wp_unique_id('cap-captcha-um-'), $mode));

            return;
        }

        $this->pendingFloating = wp_unique_id('cap-captcha-um-');
        ob_start();
    }

    public function wireSubmitButton(): void
    {
        if ($this->pendingFloating === null) {
            return;
        }

        $widgetId = $this->pendingFloating;
        $this->pendingFloating = null;
        $buttons = (string) ob_get_clean();
        $wired = preg_replace_callback(
            '/<input\b[^>]*type=(["\'])submit\1[^>]*>/i',
            static fn (array $match): string => Renderer::addFloatingAttrsToSubmit($match[0], $widgetId),
            $buttons,
            1,
            $count,
        );

        // The submit button was removed or moved by a customisation: keep a trigger of the widget's own.
        $widget = $count > 0
            ? $this->renderer->renderForMode($widgetId, Settings::MODE_FLOATING, false)
            : $this->renderer->renderForMode($widgetId, Settings::MODE_FLOATING);

        $this->printWidget($widget);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UM's own submit button markup, buffered and given two escaped data attributes.
        echo $count > 0 ? $wired : $buttons;
    }

    /** The reset form prints its submit button in the template, out of reach of a hook. */
    public function renderResetWidget(): void
    {
        $mode = $this->prepare();
        if ($mode !== null) {
            $this->renderingForm = 'password';
            $this->printWidget($this->renderer->renderForMode(wp_unique_id('cap-captcha-um-'), $mode));
        }
    }

    /**
     * UM checks the password in its own handler even when another one has
     * already added an error, and the page then says whether the password was
     * right. Without the CAPTCHA that check must not run at all.
     *
     * @param  array<string, mixed>  $submitted
     */
    public function validateLogin(array $submitted): void
    {
        if (! $this->passes($submitted)) {
            remove_action('um_submit_form_errors_hook_login', 'um_submit_form_errors_hook_login');
        }
    }

    /** @param array<string, mixed> $submitted */
    public function validateRegistration(array $submitted): void
    {
        if ($this->passes($submitted)) {
            $this->lastFailOpen = $this->verifier->wasLastFailOpen();
        }
    }

    /** @param array<string, mixed> $submitted */
    public function validateReset(array $submitted): void
    {
        $this->passes($submitted);
    }

    /**
     * @param  array<string, mixed>  $submitted  UM's unslashed copy of the POST.
     * @return bool Whether the submission may go on.
     */
    private function passes(array $submitted): bool
    {
        if (! $this->settings->isProtected($this->id())) {
            return true;
        }

        $raw = $submitted['cap-token'] ?? '';
        $token = is_string($raw) ? sanitize_text_field($raw) : '';

        if ($this->verifier->verifyToken($token, $this->id())) {
            return true;
        }

        $this->failedForm = ($submitted['mode'] ?? '') === 'password' ? 'password' : absint($submitted['form_id'] ?? 0);
        if (function_exists('UM')) {
            UM()->form()->add_error(self::ERROR_KEY, __('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap'));
        }

        return false;
    }

    /**
     * UM stores everything that was posted in the `submitted` user meta.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function removeToken(array $submitted): array
    {
        unset($submitted['cap-token']);

        return $submitted;
    }

    /** @param int|string $userId */
    public function annotateFailOpen($userId): void
    {
        if (! $this->lastFailOpen) {
            return;
        }

        $this->lastFailOpen = false;
        add_user_meta((int) $userId, 'cap_captcha_fail_open', 1, true);
        do_action('cap_captcha_fail_open_pass', $this->id(), ['user_id' => (int) $userId]);
    }

    private function prepare(): ?string
    {
        if (! $this->settings->isProtected($this->id())) {
            return null;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice(__('Ultimate Member', 'privacy-captcha-for-cap')), Renderer::ADMIN_NOTICE_KSES);

            return null;
        }

        $mode = $this->settings->getDisplayMode();
        $this->enqueuer->enqueueForMode($mode);
        $this->enqueuer->printGlobals();

        return $mode;
    }

    private function printWidget(string $widget): void
    {
        $error = $this->failedForm !== null && $this->failedForm === $this->renderingForm
            ? '<div class="um-field-error" role="alert">'.esc_html__('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap').'</div>'
            : '';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the widget attributes and text; the error text is escaped above.
        echo '<div class="um-field cap-captcha--ultimate-member">'.$widget.$error.'</div>';
    }
}
