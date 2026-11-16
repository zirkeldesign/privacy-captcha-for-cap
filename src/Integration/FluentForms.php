<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

final class FluentForms implements Integration
{
    public const ELEMENT = 'cap_captcha';

    /** @var array<int|string, string> Floating widget waiting for its form's submit button, by form id. */
    private array $pendingFloating = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'fluent_forms';
    }

    public function isAvailable(): bool
    {
        return defined('FLUENTFORM');
    }

    public function register(): void
    {
        add_filter('fluentform/editor_components', [$this, 'addComponent']);
        add_filter('fluentform/editor_element_settings_placement', [$this, 'addEditorSettings']);
        add_filter('fluentform/form_input_types', [$this, 'addInputType']);
        add_action('fluentform/render_item_'.self::ELEMENT, [$this, 'render'], 10, 2);
        add_action('fluentform/render_item_submit_button', [$this, 'wireSubmitButton'], 9, 2);
        add_filter('fluentform/validation_errors', [$this, 'validate'], 10, 3);
        add_filter('fluentform/insert_response_data', [$this, 'removeToken'], 10, 3);
    }

    /**
     * @param  array<string, array<int, mixed>>  $components
     * @return array<string, array<int, mixed>>
     */
    public function addComponent(array $components): array
    {
        $components['advanced'][] = [
            'index' => 10,
            'element' => self::ELEMENT,
            'attributes' => ['name' => 'cap-token'],
            'settings' => [
                'html_codes' => '<p>'.esc_html__('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap').'</p>',
                'validation_rules' => [],
            ],
            'editor_options' => [
                'title' => __('Privacy CAPTCHA for Cap', 'privacy-captcha-for-cap'),
                'icon_class' => 'ff-edit-recaptha',
                'template' => 'customHTML',
            ],
        ];

        return $components;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function addEditorSettings(array $settings): array
    {
        $settings[self::ELEMENT] = ['general' => [], 'advanced' => []];

        return $settings;
    }

    /**
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    public function addInputType(array $types): array
    {
        $types[] = self::ELEMENT;

        return $types;
    }

    /** @param array<string, mixed> $element */
    public function render(array $element, object $form): void
    {
        if (! $this->settings->isProtected($this->id()) || ! $this->settings->isConfigured()) {
            return;
        }

        $mode = $this->settings->getDisplayMode();
        if ($mode === Settings::MODE_PROGRAMMATIC) {
            $this->enqueuer->enqueueCore();
        } else {
            $this->enqueuer->enqueueForMode($mode);
        }
        wp_enqueue_script(
            'cap-captcha-fluent-forms',
            CAP_CAPTCHA_URL.'assets/js/fluent-forms.js',
            ['jquery'],
            CAP_CAPTCHA_VERSION,
            ['in_footer' => true]
        );
        $this->enqueuer->printGlobals();
        $widgetId = wp_unique_id('cap-captcha-fluent-');
        $ownTrigger = $mode !== Settings::MODE_FLOATING || ! $this->canUseSubmitButton($form);
        if (! $ownTrigger) {
            $this->pendingFloating[$form->id ?? 0] = $widgetId;
        }
        $widget = $this->renderer->renderForMode($widgetId, $mode, $ownTrigger);
        $error = esc_html__('CAPTCHA could not be started. Please try again.', 'privacy-captcha-for-cap');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the widget attributes and text.
        echo '<div class="ff-el-group cap-captcha--fluent"><div class="ff-el-input--content">'.$widget.'<div class="cap-captcha-fluent-error text-danger" role="alert" hidden>'.$error.'</div></div></div>';
    }

    /**
     * In floating mode the form's own submit button opens the check, so the
     * visitor sees one button instead of two that both send the form. Fluent
     * Forms names the button element differently across versions, hence the
     * filter is attached from the button's own data, for this one render.
     *
     * @param  array<string, mixed>  $button
     */
    public function wireSubmitButton(array $button, object $form): void
    {
        $formId = $form->id ?? 0;
        $widgetId = $this->pendingFloating[$formId] ?? '';
        unset($this->pendingFloating[$formId]);
        if ($widgetId === '' || ! is_string($button['element'] ?? null)) {
            return;
        }

        $hook = 'fluentform/rendering_field_html_'.$button['element'];
        $wire = static function (mixed $html) use (&$wire, $hook, $widgetId): mixed {
            remove_filter($hook, $wire);

            return is_string($html) ? Renderer::addFloatingAttrsToSubmit($html, $widgetId) : $html;
        };
        add_filter($hook, $wire);
    }

    /**
     * Step forms, a custom submit button field and a hidden submit button all
     * render no standard button after the field. Those keep the separate
     * trigger, or the hidden widget could never be opened.
     */
    private function canUseSubmitButton(object $form): bool
    {
        $fields = $form->fields ?? null;
        if (! is_array($fields) || ! isset($fields['submitButton']) || ! empty($fields['stepsWrapper'])) {
            return false;
        }

        if ($this->containsField($fields, 'custom_submit_button')) {
            return false;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms' own filter, asked the way Fluent Forms asks it.
        return ! apply_filters('fluentform/is_hide_submit_btn_'.($form->id ?? 0), false);
    }

    /**
     * Whether the form is protected is read from the form itself, never from
     * the field list Fluent Forms passes to this filter: that list leaves out
     * every field whose container is hidden by conditional logic, and the
     * conditions are evaluated against the submitted data. A submitter could
     * otherwise switch the CAPTCHA off by choosing what to send.
     *
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $formData
     * @return array<string, array<int, string>>
     */
    public function validate(array $errors, array $formData, object $form): array
    {
        if (! $this->settings->isProtected($this->id())) {
            return $errors;
        }

        if (! $this->formHasField($form)) {
            return $errors;
        }

        $raw = $formData['cap-token'] ?? '';
        $token = is_string($raw) ? sanitize_text_field($raw) : '';

        if (! $this->verifier->verifyToken($token, $this->id())) {
            $errors['cap-token'][] = __('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap');
        }

        return $errors;
    }

    private function formHasField(object $form): bool
    {
        $fields = json_decode((string) ($form->form_fields ?? ''), true);

        return is_array($fields) && $this->containsField($fields);
    }

    /**
     * Containers, steps and repeaters nest fields at different depths, so walk
     * the whole tree instead of knowing each shape.
     *
     * @param  array<int|string, mixed>  $node
     */
    private function containsField(array $node, string $element = self::ELEMENT): bool
    {
        if (($node['element'] ?? null) === $element) {
            return true;
        }

        foreach ($node as $child) {
            if (is_array($child) && $this->containsField($child, $element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public function removeToken(array $data, int $formId, array $fields): array
    {
        foreach ($fields as $field) {
            if (($field['element'] ?? '') === self::ELEMENT) {
                unset($data['cap-token']);
                break;
            }
        }

        return $data;
    }
}
