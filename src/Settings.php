<?php

declare(strict_types=1);

namespace ZirkelDesign\GFCapCaptcha;

use GFAddOn;

if (! class_exists(GFAddOn::class)) {
    return;
}

class Settings extends GFAddOn
{
    protected $_version = GF_CAP_CAPTCHA_VERSION;

    protected $_min_gravityforms_version = '2.10';

    protected $_slug = 'cap-captcha-for-gravity-forms';

    protected $_path = 'cap-captcha-for-gravity-forms/cap-captcha-for-gravity-forms.php';

    protected $_full_path = GF_CAP_CAPTCHA_FILE;

    protected $_title = 'Cap CAPTCHA for Gravity Forms';

    protected $_short_title = 'Cap CAPTCHA';

    private static ?Settings $instance = null;

    public static function get_instance(): Settings
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function plugin_settings_fields(): array
    {
        return [
            [
                'title' => esc_html__('Cap server', 'cap-captcha-for-gravity-forms'),
                'fields' => [
                    [
                        'name' => 'endpoint_base',
                        'label' => esc_html__('Endpoint base URL', 'cap-captcha-for-gravity-forms'),
                        'type' => 'text',
                        'class' => 'medium',
                        'tooltip' => esc_html__('Public URL of your Cap server, e.g. https://cap.example.com/', 'cap-captcha-for-gravity-forms'),
                        'placeholder' => 'https://cap.example.com/',
                    ],
                    [
                        'name' => 'site_key',
                        'label' => esc_html__('Site key', 'cap-captcha-for-gravity-forms'),
                        'type' => 'text',
                        'class' => 'medium',
                        'tooltip' => esc_html__('Public site key issued by your Cap server.', 'cap-captcha-for-gravity-forms'),
                    ],
                    [
                        'name' => 'secret_key',
                        'label' => esc_html__('Secret key', 'cap-captcha-for-gravity-forms'),
                        'type' => 'text',
                        'input_type' => 'password',
                        'class' => 'medium',
                        'tooltip' => esc_html__('Secret key used to call /siteverify.', 'cap-captcha-for-gravity-forms'),
                    ],
                ],
            ],
        ];
    }

    public function getEndpointBase(): string
    {
        return rtrim((string) $this->get_plugin_setting('endpoint_base'), '/').'/';
    }

    public function getSiteKey(): string
    {
        return trim((string) $this->get_plugin_setting('site_key'));
    }

    public function getSecretKey(): string
    {
        return trim((string) $this->get_plugin_setting('secret_key'));
    }

    public function isConfigured(): bool
    {
        return $this->getEndpointBase() !== '/'
            && $this->getSiteKey() !== ''
            && $this->getSecretKey() !== '';
    }

    public function getWidgetEndpoint(): string
    {
        $base = $this->getEndpointBase();
        $siteKey = $this->getSiteKey();

        if ($base === '/' || $siteKey === '') {
            return '';
        }

        return $base.$siteKey.'/';
    }
}
