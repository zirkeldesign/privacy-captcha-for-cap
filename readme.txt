=== Cap CAPTCHA ===
Contributors: zirkeldesign, dsturm
Tags: captcha, spam, proof-of-work, comments, woocommerce
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Protect WordPress comments, login, registration, WooCommerce checkout, and Gravity Forms with a self-hosted Cap (trycap.dev) proof-of-work CAPTCHA.

== Description ==

**Cap CAPTCHA** integrates [Cap](https://trycap.dev/) — a self-hosted, privacy-friendly, proof-of-work CAPTCHA — into the parts of WordPress that attract the most spam: comments, login, user registration, WooCommerce checkout, and Gravity Forms.

Unlike third-party CAPTCHAs (reCAPTCHA, hCaptcha, Turnstile), Cap runs the proof-of-work entirely in the visitor's browser and verifies the token against your own Cap server. No data leaves your infrastructure.

= Features =

* **First-class Gravity Forms field** — drag a "Cap CAPTCHA" into any form from the Advanced Fields group. Per-field display-mode override.
* **WordPress comments, wp-login, registration** integrations — togglable from one settings page.
* **WooCommerce checkout** integration — only loads when WooCommerce is active.
* **Three display modes**: inline widget, floating popover, or fully programmatic (auto-solves silently).
* **Bundled WASM** — the proof-of-work WebAssembly module ships inside the plugin (`assets/wasm/cap_wasm_bg.wasm`), so no jsdelivr or other CDN is contacted at runtime. DSGVO/GDPR-clean by default.
* **WP 6.5+ native script-module API** for proper ES-module loading.
* **i18n-ready** with German translations bundled.
* **Theme-friendly CSS** built on Gravity Forms Orbital tokens with `--cap-captcha-*` overrides.
* **Filter hooks** for asset URLs, button classes, i18n strings, and the display mode.

= Requirements =

* WordPress 6.5 or later
* PHP 8.3 or later
* A reachable Cap server (see https://trycap.dev/)
* Gravity Forms 2.5+ (only if you enable the Gravity Forms integration)
* WooCommerce (only if you enable the WooCommerce integration)

== Installation ==

1. Upload the `cap-captcha` folder to `/wp-content/plugins/`.
2. Activate the plugin via the *Plugins* menu in WordPress.
3. Go to **Settings → Cap CAPTCHA** and enter your Cap endpoint URL, site key, and secret key.
4. Tick the integrations you want to enable.

== Frequently Asked Questions ==

= Where do I get a Cap endpoint, site key and secret? =

You provision those in your self-hosted Cap server. See the Cap documentation at https://trycap.dev/.

= Where is the WASM loaded from? =

By default: from the copy bundled inside this plugin at `wp-content/plugins/cap-captcha/assets/wasm/cap_wasm_bg.wasm`. You can switch to your Cap server's own `/assets/cap_wasm_bg.wasm` endpoint or to the upstream jsdelivr CDN under **Settings → Cap CAPTCHA → Privacy**.

= Can I store the secret outside the database? =

Yes. Define `CAP_CAPTCHA_SECRET_KEY` in `wp-config.php` and the plugin will use it instead of the value saved in `wp_options`.

= What is "fail-open" mode? =

When enabled, the plugin lets submissions through if the Cap server is unreachable. Off by default — only turn it on if temporary outages must not block legitimate users (logins, checkouts).

= Does the bundled `cap-widget` script make any external requests? =

It loads `pako` from jsdelivr in the current upstream build. There's no override hook for that yet ([cap#56](https://github.com/tiagozip/cap/issues/56)). For full air-gap, ship your own custom widget build via the `cap_captcha_widget_src` filter.

== Changelog ==

= 1.0.0 =
* Initial release.
* Gravity Forms field (inline / floating / programmatic display).
* Comments, login, registration, WooCommerce integrations.
* Bundled WASM and cap-widget assets — DSGVO-clean by default.
* WP 6.5 native script-module loading.
* Top-level Settings → Cap CAPTCHA page with integration toggles, WASM source choice, fail-open switch.
* German translations.
