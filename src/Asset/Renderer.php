<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Asset;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * Renders the `<cap-widget>` (or programmatic hidden input + trigger button)
 * HTML used across every integration. Keeps GF, comments, login, registration,
 * and WooCommerce integrations in lock-step on attribute names and i18n.
 */
final class Renderer
{
    /**
     * Allowed HTML for the admin notice, so callers can echo it through
     * wp_kses() instead of suppressing the output-escaping sniff.
     *
     * @var array<string, array<string, array<string, bool>>>
     */
    public const ADMIN_NOTICE_KSES = [
        'div' => ['class' => [], 'role' => []],
        'em' => [],
    ];

    public function __construct(private readonly Settings $settings) {}

    /**
     * Admin-only inline notice surfaced when an enabled integration is missing
     * configuration. Returns empty for users without the capability.
     */
    public function renderAdminNotice(string $integrationLabel = ''): string
    {
        if (! $this->settings->shouldShowAdminNotices()) {
            return '';
        }

        $message = $integrationLabel === ''
            ? esc_html__('Privacy CAPTCHA for Cap is not configured. Set the endpoint and keys in Settings → Privacy CAPTCHA for Cap.', 'privacy-captcha-for-cap')
            : sprintf(
                /* translators: %s is the integration label, e.g. "Comments". */
                esc_html__('Privacy CAPTCHA for Cap (%s) is not configured. Set the endpoint and keys in Settings → Privacy CAPTCHA for Cap.', 'privacy-captcha-for-cap'),
                esc_html($integrationLabel)
            );

        return sprintf(
            '<div class="cap-captcha cap-captcha--admin-notice" role="status"><em>%s</em></div>',
            $message
        );
    }

    public function renderForMode(string $inputId, ?string $mode = null, bool $includeFloatingTrigger = true): string
    {
        $endpoint = $this->settings->getWidgetEndpoint();
        if ($endpoint === '') {
            return '';
        }

        $resolvedMode = $mode ?? $this->settings->getDisplayMode();
        $i18n = $this->buildI18nAttributes();

        return match ($resolvedMode) {
            Settings::MODE_PROGRAMMATIC => $this->programmatic(),
            Settings::MODE_FLOATING => $this->floating($inputId, $endpoint, $i18n, $includeFloatingTrigger),
            default => $this->inline($inputId, $endpoint, $i18n),
        };
    }

    /**
     * Inject `data-cap-floating="#widgetId"` + position attribute into the
     * first <input> / <button> tag in the given HTML. Used by integrations
     * that hook the form's existing submit button (GF, Comments, WC) so the
     * primary button doubles as the floating trigger — no separate widget UI.
     */
    public static function addFloatingAttrsToSubmit(string $buttonHtml, string $widgetId, string $position = 'top'): string
    {
        if ($buttonHtml === '' || str_contains($buttonHtml, 'data-cap-floating')) {
            return $buttonHtml;
        }

        $attrs = sprintf(
            ' data-cap-floating="#%s" data-cap-floating-position="%s"',
            esc_attr($widgetId),
            esc_attr($position)
        );

        $replaced = preg_replace('/<(input|button)\b/i', '<$1'.$attrs, $buttonHtml, 1);

        return $replaced ?? $buttonHtml;
    }

    private function inline(string $inputId, string $endpoint, string $i18nAttrs): string
    {
        return sprintf(
            '<div class="cap-captcha cap-captcha--inline"><cap-widget id="%s" data-cap-api-endpoint="%s"%s></cap-widget></div>',
            esc_attr($inputId),
            esc_attr($endpoint),
            $i18nAttrs
        );
    }

    private function floating(string $inputId, string $endpoint, string $i18nAttrs, bool $includeTrigger): string
    {
        if (! $includeTrigger) {
            // Integration takes responsibility for wiring the form's existing
            // submit button via Renderer::addFloatingAttrsToSubmit().
            return sprintf(
                '<cap-widget id="%s" data-cap-api-endpoint="%s" hidden%s></cap-widget>',
                esc_attr($inputId),
                esc_attr($endpoint),
                $i18nAttrs
            );
        }

        // Standalone-trigger flow: mark the widget for the auto-submit helper
        // so the form fires automatically once the challenge resolves.
        $widget = sprintf(
            '<cap-widget id="%s" data-cap-api-endpoint="%s" data-cap-autosubmit hidden%s></cap-widget>',
            esc_attr($inputId),
            esc_attr($endpoint),
            $i18nAttrs
        );

        $position = (string) apply_filters('cap_captcha_floating_position', 'top');
        $buttonClasses = trim((string) apply_filters(
            'cap_captcha_floating_button_classes',
            'button cap-captcha-floating-trigger'
        ));

        return sprintf(
            '<div class="cap-captcha cap-captcha--floating">%s'
            .'<button type="button" class="%s" data-cap-floating="#%s" data-cap-floating-position="%s">%s</button>'
            .'</div>',
            $widget,
            esc_attr($buttonClasses),
            esc_attr($inputId),
            esc_attr($position),
            esc_html__('Verify you are human', 'privacy-captcha-for-cap')
        );
    }

    private function programmatic(): string
    {
        // Programmatic mode: hidden input populated by JS solve() on page load.
        return '<input type="hidden" name="cap-token" class="cap-captcha cap-captcha--programmatic" data-cap-programmatic="1">';
    }

    /**
     * Build the `data-cap-i18n-*` HTML attributes from the active locale.
     */
    private function buildI18nAttributes(): string
    {
        $defaults = [
            'initial-state' => __("I'm not a robot", 'privacy-captcha-for-cap'),
            'verifying-label' => __('Verifying…', 'privacy-captcha-for-cap'),
            'solved-label' => __('Verified', 'privacy-captcha-for-cap'),
            'error-label' => __('Error. Please try again.', 'privacy-captcha-for-cap'),
            'verify-aria-label' => __('Start CAPTCHA verification', 'privacy-captcha-for-cap'),
            'verifying-aria-label' => __('Verifying CAPTCHA', 'privacy-captcha-for-cap'),
            'verified-aria-label' => __('CAPTCHA verified', 'privacy-captcha-for-cap'),
        ];

        $strings = apply_filters('cap_captcha_i18n', $defaults);
        if (! is_array($strings)) {
            $strings = $defaults;
        }

        $out = '';
        foreach ($strings as $key => $value) {
            if (! is_string($key) || ! is_string($value) || $value === '') {
                continue;
            }
            $out .= sprintf(' data-cap-i18n-%s="%s"', esc_attr($key), esc_attr($value));
        }

        return $out;
    }
}
