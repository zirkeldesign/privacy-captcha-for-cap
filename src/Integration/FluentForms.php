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
        add_filter('fluentform/editor_element_customization_settings', [$this, 'addEditorCustomization']);
        add_filter('fluentform/editor_init_element_'.self::ELEMENT, [$this, 'initializeField']);
        add_filter('fluentform/form_input_types', [$this, 'addInputType']);
        add_action('fluentform/render_item_'.self::ELEMENT, [$this, 'render'], 10, 2);
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
                'cap_display_mode' => '',
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
        $settings[self::ELEMENT] = ['general' => ['cap_display_mode'], 'advanced' => []];

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function addEditorCustomization(array $settings): array
    {
        $settings['cap_display_mode'] = [
            'template' => 'select',
            'label' => __('Display mode', 'privacy-captcha-for-cap'),
            'options' => [
                ['value' => '', 'label' => __('Use global setting', 'privacy-captcha-for-cap')],
                ['value' => Settings::MODE_INLINE, 'label' => __('Inline (always visible)', 'privacy-captcha-for-cap')],
                ['value' => Settings::MODE_FLOATING, 'label' => __('Floating (opens on click)', 'privacy-captcha-for-cap')],
                ['value' => Settings::MODE_PROGRAMMATIC, 'label' => __('Programmatic (auto-solves silently)', 'privacy-captcha-for-cap')],
            ],
        ];

        return $settings;
    }

    public function initializeField(mixed $element): mixed
    {
        if (! is_array($element)) {
            return $element;
        }
        if (! isset($element['settings']) || ! is_array($element['settings'])) {
            $element['settings'] = [];
        }
        $element['settings']['cap_display_mode'] ??= '';

        return $element;
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

        $override = $element['settings']['cap_display_mode'] ?? '';
        $mode = in_array($override, [Settings::MODE_INLINE, Settings::MODE_FLOATING, Settings::MODE_PROGRAMMATIC], true)
            ? $override
            : $this->settings->getDisplayMode();
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
        $widget = $this->renderer->renderForMode(wp_unique_id('cap-captcha-fluent-'), $mode);
        $error = esc_html__('CAPTCHA could not be started. Please try again.', 'privacy-captcha-for-cap');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the widget attributes and text.
        echo '<div class="ff-el-group cap-captcha--fluent"><div class="ff-el-input--content">'.$widget.'<div class="cap-captcha-fluent-error text-danger" role="alert" hidden>'.$error.'</div></div></div>';
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
    private function containsField(array $node): bool
    {
        if (($node['element'] ?? null) === self::ELEMENT) {
            return true;
        }

        foreach ($node as $child) {
            if (is_array($child) && $this->containsField($child)) {
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
