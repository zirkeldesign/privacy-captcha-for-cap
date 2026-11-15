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
        add_filter('fluentform/form_input_types', [$this, 'addInputType']);
        add_action('fluentform/render_item_'.self::ELEMENT, [$this, 'render'], 10, 2);
        add_filter('fluentform/validation_errors', [$this, 'validate'], 10, 4);
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
        $widget = $this->renderer->renderForMode(wp_unique_id('cap-captcha-fluent-'), $mode);
        $error = esc_html__('CAPTCHA could not be started. Please try again.', 'privacy-captcha-for-cap');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the widget attributes and text.
        echo '<div class="ff-el-group cap-captcha--fluent"><div class="ff-el-input--content">'.$widget.'<div class="cap-captcha-fluent-error text-danger" role="alert" hidden>'.$error.'</div></div></div>';
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $formData
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<int, string>>
     */
    public function validate(array $errors, array $formData, object $form, array $fields): array
    {
        if (! $this->settings->isProtected($this->id())) {
            return $errors;
        }

        foreach ($fields as $field) {
            if (($field['element'] ?? '') !== self::ELEMENT) {
                continue;
            }

            $raw = $formData['cap-token'] ?? '';
            $token = is_string($raw) ? sanitize_text_field($raw) : '';
            if (! $this->verifier->verifyToken($token, $this->id())) {
                $errors['cap-token'][] = __('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap');
            }
            break;
        }

        return $errors;
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
