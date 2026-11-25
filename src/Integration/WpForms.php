<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * Protects WPForms forms. Whether a form is protected comes from its own
 * "Default / Always / Never" setting in the builder, Default following the
 * global "protect every form" option. The widget renders just above the submit
 * button, the token is checked on `wpforms_process`.
 */
final class WpForms implements Integration
{
    /** The per-form setting, stored in the form's `settings`. */
    public const MODE_KEY = 'cap_captcha_mode';

    /** Set on an AJAX error response when the token never went to Cap, so the script keeps it. */
    public const KEPT_KEY = 'cap_captcha_kept';

    /** Before payment handlers on the same hook at 10, so a failed check charges nothing. */
    private const PROCESS_PRIORITY = 5;

    private bool $lastFailOpen = false;

    /** Whether this request's token went to Cap; it is spent then. */
    private bool $tokenSpent = false;

    /** Widget id waiting for the submit button WPForms prints next, in floating mode. */
    private ?string $pendingFloating = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'wpforms';
    }

    public function isAvailable(): bool
    {
        return defined('WPFORMS_VERSION') && function_exists('wpforms');
    }

    public function register(): void
    {
        add_action('wpforms_display_submit_before', [$this, 'renderWidget'], 10, 1);
        // Before the PayPal Commerce button WPForms prints at 9.
        add_action('wpforms_display_submit_after', [$this, 'wireSubmitButton'], 1, 2);
        add_action('wpforms_admin_builder_anti_spam_panel_content', [$this, 'renderFormSetting'], 10, 1);
        add_action('wpforms_process', [$this, 'verify'], self::PROCESS_PRIORITY, 3);
        add_filter('wpforms_ajax_submit_errors_response', [$this, 'markTokenKept'], 10, 3);
        add_action('wpforms_process_entry_saved', [$this, 'annotateFailOpen'], 10, 4);
    }

    /** @param array<string, mixed> $formData */
    public function isFormProtected(array $formData): bool
    {
        if (! $this->settings->isProtected($this->id())) {
            return false;
        }

        $settings = is_array($formData['settings'] ?? null) ? $formData['settings'] : [];

        return match ($settings[self::MODE_KEY] ?? 'default') {
            'always' => true,
            'never' => false,
            default => $this->settings->isWpFormsProtectAll(),
        };
    }

    /**
     * Fires inside the form, in the submit container right before the button,
     * for every way a form is embedded (shortcode, block, widget, preview).
     */
    public function renderWidget(mixed $formData = []): void
    {
        // An AMP page runs none of the widget's JavaScript.
        if (! is_array($formData) || ! $this->isFormProtected($formData) || (function_exists('wpforms_is_amp') && wpforms_is_amp())) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice('WPForms'), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        // The shared programmatic script writes one token into every field; each WPForms form needs its own.
        $mode === Settings::MODE_PROGRAMMATIC ? $this->enqueuer->enqueueCore() : $this->enqueuer->enqueueForMode($mode);
        wp_enqueue_script(
            'cap-captcha-wpforms',
            CAP_CAPTCHA_URL.'assets/js/wpforms.js',
            ['jquery'],
            CAP_CAPTCHA_VERSION,
            ['in_footer' => true]
        );
        $this->enqueuer->printGlobals();

        // WPForms has no instance counter: the same form twice on a page needs two ids.
        $widgetId = wp_unique_id('cap-captcha-wpforms-');
        if ($mode !== Settings::MODE_FLOATING) {
            $this->printWidget($this->renderer->renderForMode($widgetId, $mode));

            return;
        }

        // WPForms has no filter on its submit button: capture it until wpforms_display_submit_after.
        $this->pendingFloating = $widgetId;
        ob_start();
    }

    /**
     * In floating mode the form's own submit button opens the check. It is
     * wired on the server, so the floating script finds it when it first scans
     * the page.
     */
    public function wireSubmitButton(mixed $formData = [], mixed $button = 'submit'): void
    {
        if ($this->pendingFloating === null || $button !== 'submit') {
            return;
        }

        $widgetId = $this->pendingFloating;
        $this->pendingFloating = null;
        $html = (string) ob_get_clean();
        $wired = (string) preg_replace_callback(
            '/<button\b[^>]*\btype=(["\'])submit\1[^>]*>/i',
            static fn (array $match): string => Renderer::addFloatingAttrsToSubmit($match[0], $widgetId),
            $html,
            1,
            $count,
        );

        // The submit button was removed or replaced by a customisation: keep a trigger of the widget's own.
        $this->printWidget($this->renderer->renderForMode($widgetId, Settings::MODE_FLOATING, $count === 0));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WPForms' own submit button markup, buffered and given two escaped data attributes.
        echo $count > 0 ? $wired : $html;
    }

    /**
     * The per-form "Default / Always / Never" select in the builder's
     * "Spam Protection and Security" panel, saved as `settings[cap_captcha_mode]`.
     */
    public function renderFormSetting(mixed $formData = []): void
    {
        if (! is_array($formData) || ! function_exists('wpforms_panel_field') || ! function_exists('wpforms_panel_fields_group')) {
            return;
        }

        $select = wpforms_panel_field(
            'select',
            'settings',
            self::MODE_KEY,
            $formData,
            // Not "Protection": WPForms heads its own anti-spam switches with that word right above.
            __('Protect this form', 'privacy-captcha-for-cap'),
            [
                'default' => 'default',
                'options' => [
                    'default' => __('Default (use the global setting)', 'privacy-captcha-for-cap'),
                    'always' => __('Always protect this form', 'privacy-captcha-for-cap'),
                    'never' => __('Never protect this form', 'privacy-captcha-for-cap'),
                ],
                'tooltip' => __('Default follows “Automatically protect every WPForms form” in Settings → Privacy CAPTCHA for Cap.', 'privacy-captcha-for-cap'),
            ],
            false
        );

        wpforms_panel_fields_group(
            (string) $select,
            [
                'title' => __('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'),
                'description' => __('Whether visitors solve a Cap challenge before this form is sent.', 'privacy-captcha-for-cap'),
            ]
        );
    }

    /**
     * Runs after WPForms validated the fields and ran its own spam checks. A
     * submission those refused keeps its token for the corrected try. The
     * check runs for AMP requests too: a client could otherwise claim AMP to
     * skip it.
     */
    public function verify(mixed $fields = [], mixed $entry = [], mixed $formData = []): void
    {
        $this->tokenSpent = false;
        $this->lastFailOpen = false;
        if (! is_array($formData) || ! $this->isFormProtected($formData) || ! function_exists('wpforms')) {
            return;
        }

        $process = wpforms()->obj('process');
        $formId = (int) ($formData['id'] ?? 0);
        if (! is_object($process) || ! empty($process->errors[$formId])) {
            return;
        }

        $this->tokenSpent = true;
        if (! $this->verifier->verifyCurrentRequest($this->id())) {
            $process->errors[$formId]['footer'] = __('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap');

            return;
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();
    }

    /**
     * Tells the script whether the token of a refused AJAX submission is still
     * unspent. Without the flag it renews the token, so any failure it cannot
     * place (a rejected token, a payment refused after the check) gets a new one.
     *
     * @param  mixed  $response
     * @return mixed
     */
    public function markTokenKept($response, mixed $formId = 0, mixed $formData = [])
    {
        if (is_array($response) && is_array($formData) && ! $this->tokenSpent && $this->isFormProtected($formData)) {
            $response[self::KEPT_KEY] = true;
        }

        return $response;
    }

    /**
     * Tag an entry saved from a submission that passed only because Cap was
     * unreachable. WPForms Lite stores no entries; the action fires either way.
     */
    public function annotateFailOpen(mixed $fields = [], mixed $entry = [], mixed $formData = [], mixed $entryId = 0): void
    {
        if (! $this->lastFailOpen) {
            return;
        }

        $this->lastFailOpen = false;
        $formId = is_array($formData) ? (int) ($formData['id'] ?? 0) : 0;
        $entryId = is_numeric($entryId) ? (int) $entryId : 0;

        if ($entryId > 0 && function_exists('wpforms') && wpforms()->is_pro()) {
            $meta = wpforms()->obj('entry_meta');
            if (is_object($meta) && method_exists($meta, 'add')) {
                $meta->add([
                    'entry_id' => $entryId,
                    'form_id' => $formId,
                    'user_id' => get_current_user_id(),
                    'type' => 'cap_captcha_fail_open',
                    'data' => 1,
                ], 'entry_meta');
            }
        }

        do_action('cap_captcha_fail_open_pass', $this->id(), ['form_id' => $formId, 'entry_id' => $entryId]);
    }

    private function printWidget(string $widget): void
    {
        $error = esc_html__('CAPTCHA could not be started. Please try again.', 'privacy-captcha-for-cap');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the widget attributes and text.
        echo '<div class="cap-captcha--wpforms">'.$widget.'<div class="cap-captcha-wpforms-error cap-captcha-error wpforms-error" role="alert" hidden>'.$error.'</div></div>';
    }
}
