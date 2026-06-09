<?php

declare(strict_types=1);

namespace ZirkelDesign\GFCapCaptcha\Field;

use GF_Field;
use ZirkelDesign\GFCapCaptcha\Settings;

if (! class_exists(GF_Field::class)) {
    return;
}

final class CapCaptchaField extends GF_Field
{
    /** @var string */
    public $type = 'cap_captcha';

    public function get_form_editor_field_title(): string
    {
        return esc_attr__('Cap CAPTCHA', 'cap-captcha-for-gravity-forms');
    }

    public function get_form_editor_field_description(): string
    {
        return esc_attr__('Adds a Cap challenge that must be solved before the form can be submitted.', 'cap-captcha-for-gravity-forms');
    }

    public function get_form_editor_field_icon(): string
    {
        return 'gform-icon--shield-check';
    }

    /**
     * @return array<string, string>
     */
    public function get_form_editor_button(): array
    {
        return [
            'group' => 'advanced_fields',
            'text' => $this->get_form_editor_field_title(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function get_form_editor_field_settings(): array
    {
        return [
            'label_setting',
            'description_setting',
            'css_class_setting',
            'admin_label_setting',
        ];
    }

    public function is_conditional_logic_supported(): bool
    {
        return false;
    }

    public function is_value_submission_empty($form_id): bool
    {
        $token = $_POST['cap-token'] ?? '';

        return ! is_string($token) || trim($token) === '';
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  string|array<mixed>  $value
     * @param  array<string, mixed>|null  $entry
     */
    public function get_field_input($form, $value = '', $entry = null): string
    {
        $settings = Settings::get_instance();
        $endpoint = $settings->getWidgetEndpoint();

        $formId = (int) ($form['id'] ?? 0);
        $fieldId = (int) $this->id;
        $inputId = "input_{$formId}_{$fieldId}";

        $cssClass = trim('ginput_container ginput_container_cap_captcha '.($this->cssClass ?? ''));

        if ($endpoint === '') {
            $notice = esc_html__('Cap CAPTCHA is not configured. Set the endpoint and keys in Forms → Settings → Cap CAPTCHA.', 'cap-captcha-for-gravity-forms');

            return sprintf(
                '<div class="%s"><em>%s</em></div>',
                esc_attr($cssClass),
                $notice
            );
        }

        return sprintf(
            '<div class="%s"><cap-widget id="%s" data-cap-api-endpoint="%s"></cap-widget></div>',
            esc_attr($cssClass),
            esc_attr($inputId),
            esc_attr($endpoint)
        );
    }
}
