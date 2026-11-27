<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * Protects Elementor Pro's form widget. A form is protected when the editor
 * added the "Privacy CAPTCHA for Cap" field to it. Every submission reaches
 * Elementor through one admin-ajax action, and whether the form holds the
 * field is read from the stored widget, never from the request.
 */
final class ElementorPro implements Integration
{
    public const FIELD_TYPE = 'cap_captcha';

    /** After Elementor's own validators, so a field error leaves the token unspent. */
    private const VALIDATION_PRIORITY = 99;

    private bool $lastFailOpen = false;

    /** @var array<int, string> Floating widgets waiting for their form's submit button. */
    private array $pendingFloating = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'elementor_pro';
    }

    public function isAvailable(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION');
    }

    public function register(): void
    {
        add_filter('elementor_pro/forms/field_types', [$this, 'addFieldType']);
        add_filter('elementor_pro/forms/render/item', [$this, 'prepareItem']);
        add_action('elementor_pro/forms/render_field/'.self::FIELD_TYPE, [$this, 'render'], 10, 1);
        add_filter('elementor/element/is_dynamic_content', [$this, 'renderOnEveryRequest'], 10, 2);
        add_filter('elementor/widget/render_content', [$this, 'wireSubmitButton']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueueEditorPreview']);
        add_action('elementor_pro/forms/validation', [$this, 'validate'], self::VALIDATION_PRIORITY, 2);
        add_action('elementor_pro/forms/new_record', [$this, 'annotateFailOpen'], 10, 1);
    }

    /**
     * @param  mixed  $types
     * @return mixed
     */
    public function addFieldType($types)
    {
        if (is_array($types)) {
            $types[self::FIELD_TYPE] = __('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap');
        }

        return $types;
    }

    /**
     * The widget carries its own label, and "required" means nothing for it.
     *
     * @param  mixed  $item
     * @return mixed
     */
    public function prepareItem($item)
    {
        if (is_array($item) && ($item['field_type'] ?? '') === self::FIELD_TYPE) {
            $item['field_label'] = false;
            $item['required'] = false;
        }

        return $item;
    }

    /**
     * Elementor caches the HTML of a form widget and replays it without
     * running the field's renderer: the widget's scripts would be missing from
     * the second page view on. A form holding the field is rendered each time.
     *
     * @param  mixed  $dynamic
     * @param  mixed  $element  The element's stored data.
     * @return mixed
     */
    public function renderOnEveryRequest($dynamic, $element = [])
    {
        if ($dynamic || ! is_array($element) || ($element['widgetType'] ?? '') !== 'form') {
            return $dynamic;
        }

        $fields = $element['settings']['form_fields'] ?? [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && ($field['field_type'] ?? '') === self::FIELD_TYPE) {
                return true;
            }
        }

        return $dynamic;
    }

    public function render(mixed $item = []): void
    {
        if (! $this->settings->isProtected($this->id())) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice('Elementor Pro'), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        // The shared programmatic script writes one token into every field; each form needs its own.
        $mode === Settings::MODE_PROGRAMMATIC ? $this->enqueuer->enqueueCore() : $this->enqueuer->enqueueForMode($mode);
        wp_enqueue_script(
            'cap-captcha-elementor-forms',
            CAP_CAPTCHA_URL.'assets/js/elementor-forms.js',
            ['jquery'],
            CAP_CAPTCHA_VERSION,
            ['in_footer' => true]
        );
        $this->enqueuer->printGlobals();

        $widgetId = wp_unique_id('cap-captcha-elementor-');
        if ($mode === Settings::MODE_FLOATING) {
            // Elementor prints the submit button after the fields: the widget is
            // filled in once the whole form is rendered and the button is known.
            $this->pendingFloating[] = $widgetId;
            $widget = self::placeholder($widgetId);
        } else {
            $widget = $this->renderer->renderForMode($widgetId, $mode);
        }

        $error = esc_html__('CAPTCHA could not be started. Please try again.', 'privacy-captcha-for-cap');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the widget attributes and text.
        echo '<div class="elementor-field cap-captcha--elementor">'.$widget.'<div class="cap-captcha-elementor-error cap-captcha-error elementor-message elementor-message-danger" role="alert" hidden>'.$error.'</div></div>';
    }

    /**
     * In floating mode the form's own submit button opens the check, so the
     * visitor sees one button. Runs on the rendered form widget; a form whose
     * button was removed or replaced gets a trigger of the widget's own.
     *
     * @param  mixed  $content
     * @return mixed
     */
    public function wireSubmitButton($content)
    {
        if (! is_string($content) || $this->pendingFloating === []) {
            return $content;
        }

        foreach ($this->pendingFloating as $index => $widgetId) {
            $placeholder = self::placeholder($widgetId);
            if (! str_contains($content, $placeholder)) {
                continue;
            }

            unset($this->pendingFloating[$index]);
            $wired = (string) preg_replace_callback(
                '/<button\b[^>]*\btype=(["\'])submit\1[^>]*>/i',
                static fn (array $match): string => Renderer::addFloatingAttrsToSubmit($match[0], $widgetId),
                $content,
                1,
                $count,
            );
            $content = str_replace(
                $placeholder,
                $this->renderer->renderForMode($widgetId, Settings::MODE_FLOATING, $count === 0),
                $count > 0 ? $wired : $content
            );
        }

        return $content;
    }

    /** The form preview in the editor is drawn by JavaScript and knows nothing about the field without this. */
    public function enqueueEditorPreview(): void
    {
        wp_enqueue_script(
            'cap-captcha-elementor-editor',
            CAP_CAPTCHA_URL.'assets/js/elementor-editor.js',
            [],
            CAP_CAPTCHA_VERSION,
            ['in_footer' => true]
        );
        wp_add_inline_script(
            'cap-captcha-elementor-editor',
            sprintf('window.CAP_CAPTCHA_ELEMENTOR_LABEL = %s;', wp_json_encode(__('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'))),
            'before'
        );
    }

    /**
     * Fires after Elementor's own field validation. A submission those
     * validators refused keeps its token for the corrected try.
     */
    public function validate(mixed $record = null, mixed $ajaxHandler = null): void
    {
        $this->lastFailOpen = false;
        if (! is_object($record) || ! is_object($ajaxHandler) || ! $this->settings->isProtected($this->id())) {
            return;
        }

        $fieldId = $this->fieldId($record);
        if ($fieldId === null) {
            return;
        }

        // Elementor's "required" check knows nothing about the token: the widget's verdict replaces it.
        $errors = is_array($ajaxHandler->errors ?? null) ? $ajaxHandler->errors : [];
        $flaggedByElementor = array_key_exists($fieldId, $errors);
        unset($errors[$fieldId]);
        $ajaxHandler->errors = $errors;
        if ($errors !== []) {
            return;
        }

        if (! $this->verifier->verifyCurrentRequest($this->id())) {
            $ajaxHandler->add_error($fieldId, __('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap'));

            return;
        }

        if ($flaggedByElementor) {
            $ajaxHandler->set_success(true);
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();
        // Keeps the field out of notification mails and stored submissions.
        $record->remove_field($fieldId);
    }

    public function annotateFailOpen(mixed $record = null): void
    {
        if (! $this->lastFailOpen) {
            return;
        }

        $this->lastFailOpen = false;
        $formId = is_object($record) ? $record->get_form_settings('id') : '';
        do_action('cap_captcha_fail_open_pass', $this->id(), ['form_id' => is_scalar($formId) ? (string) $formId : '']);
    }

    /** The id of the CAPTCHA field in the stored form, or null when the form has none. */
    private function fieldId(object $record): ?string
    {
        $fields = $record->get_form_settings('form_fields');
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && ($field['field_type'] ?? '') === self::FIELD_TYPE) {
                return (string) ($field['custom_id'] ?? '');
            }
        }

        return null;
    }

    private static function placeholder(string $widgetId): string
    {
        return '<!--'.$widgetId.'-->';
    }
}
