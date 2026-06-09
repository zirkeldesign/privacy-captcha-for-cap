<?php

/**
 * Plugin Name:       Cap CAPTCHA for Gravity Forms
 * Plugin URI:        https://github.com/zirkeldesign/cap-captcha-for-gravity-forms
 * Description:       Adds a Cap (trycap.dev) CAPTCHA field with server-side verification to Gravity Forms.
 * Version:           1.0.0
 * Author:            zirkel.design
 * Author URI:        https://zirkel.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cap-captcha-for-gravity-forms
 * Domain Path:       /languages
 * Requires PHP:      8.3
 * Requires at least: 6.5
 */

declare(strict_types=1);
use ZirkelDesign\GFCapCaptcha\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('GF_CAP_CAPTCHA_VERSION', '1.0.0');
define('GF_CAP_CAPTCHA_FILE', __FILE__);
define('GF_CAP_CAPTCHA_DIR', plugin_dir_path(__FILE__));
define('GF_CAP_CAPTCHA_URL', plugin_dir_url(__FILE__));

if (file_exists(GF_CAP_CAPTCHA_DIR.'vendor/autoload.php')) {
    require_once GF_CAP_CAPTCHA_DIR.'vendor/autoload.php';
}

add_action('plugins_loaded', static function (): void {
    if (! class_exists('GFForms') && ! class_exists('GFAPI')) {
        add_action('admin_notices', static function (): void {
            if (! current_user_can('activate_plugins')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'Cap CAPTCHA for Gravity Forms requires Gravity Forms to be installed and activated.',
                    'cap-captcha-for-gravity-forms'
                )
            );
        });

        return;
    }

    Plugin::boot();
});
