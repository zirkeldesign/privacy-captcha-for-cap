<?php

declare(strict_types=1);

namespace ZirkelDesign\GFCapCaptcha;

use GF_Fields;
use GFAddOn;
use ZirkelDesign\GFCapCaptcha\Field\CapCaptchaField;
use ZirkelDesign\GFCapCaptcha\Validation\SubmissionValidator;

final class Plugin
{
    public static function boot(): void
    {
        if (class_exists(GFAddOn::class)) {
            GFAddOn::register(Settings::class);
        }

        add_action('gform_loaded', [self::class, 'registerField'], 5);

        $validator = new SubmissionValidator(static fn (): Settings => Settings::get_instance());
        add_filter('gform_validation', [$validator, 'validate']);

        add_action('gform_enqueue_scripts', [self::class, 'enqueueWidget'], 10, 2);
        add_action('wp_head', [self::class, 'printWidgetGlobals'], 1);
    }

    public const WIDGET_MODULE_ID = 'cap-captcha-for-gravity-forms/widget';

    public static function registerField(): void
    {
        if (! class_exists(GF_Fields::class)) {
            return;
        }

        GF_Fields::register(new CapCaptchaField);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public static function enqueueWidget(array $form, bool $isAjax): void
    {
        if (! self::formHasCapField($form)) {
            return;
        }

        $src = (string) apply_filters(
            'cap_captcha_for_gravity_forms_widget_src',
            GF_CAP_CAPTCHA_URL.'assets/js/vendor/cap-widget.js'
        );

        wp_enqueue_script_module(
            self::WIDGET_MODULE_ID,
            $src,
            [],
            GF_CAP_CAPTCHA_VERSION
        );
    }

    /**
     * Emit the optional CAP_CUSTOM_WASM_URL global so air-gapped deployments can
     * point the widget at a self-hosted WASM build instead of jsdelivr. Runs at
     * wp_head priority 1 so the assignment lands before the module script tag.
     */
    public static function printWidgetGlobals(): void
    {
        $wasmUrl = (string) apply_filters('cap_captcha_for_gravity_forms_wasm_url', '');

        if ($wasmUrl === '') {
            return;
        }

        printf(
            "<script>window.CAP_CUSTOM_WASM_URL=%s;</script>\n",
            wp_json_encode($wasmUrl)
        );
    }

    /**
     * @param  array<string, mixed>  $form
     */
    public static function formHasCapField(array $form): bool
    {
        if (empty($form['fields']) || ! is_array($form['fields'])) {
            return false;
        }

        foreach ($form['fields'] as $field) {
            if (is_object($field) && isset($field->type) && $field->type === 'cap_captcha') {
                return true;
            }
        }

        return false;
    }
}
