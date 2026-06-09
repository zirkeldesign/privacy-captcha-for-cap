=== Cap CAPTCHA for Gravity Forms ===
Contributors: zirkeldesign, dsturm
Tags: gravity forms, captcha, cap, spam, privacy
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a Cap (trycap.dev) CAPTCHA field with server-side verification to Gravity Forms.

== Description ==

**Cap CAPTCHA for Gravity Forms** lets you protect any Gravity Form with a [Cap](https://trycap.dev/) challenge from your own self-hosted Cap server. A new field type appears in the form editor under *Advanced Fields*; drag it onto your form and submissions without a valid token are rejected server-side.

= Features =

* Drop-in **Cap CAPTCHA** field for the Gravity Forms editor
* **Server-side verification** against your Cap server's `/siteverify` endpoint
* **Self-hosted friendly** — point at any Cap instance (Docker, Kubernetes, bare-metal)
* **No third-party tracking** — no Google, no Cloudflare, no external CDNs by default
* **Translatable** error messages

== Installation ==

= From WordPress Admin =

1. Upload the `cap-captcha-for-gravity-forms` folder to `/wp-content/plugins/`.
2. Activate the plugin through the *Plugins* menu in WordPress.
3. Go to **Forms → Settings → Cap CAPTCHA** and enter your Cap endpoint URL, site key, and secret key.
4. Edit a form and drag the **Cap CAPTCHA** field from *Advanced Fields* onto your form.

= Requirements =

* WordPress 6.5 or later (uses the native script-module API)
* PHP 8.3 or later
* Gravity Forms 2.10 or later
* A reachable Cap server (see https://trycap.dev/)

== Frequently Asked Questions ==

= Where do I get a Cap endpoint, site key and secret? =

You configure those in your self-hosted Cap server. See the Cap documentation at https://trycap.dev/.

= Can I use the Cap CDN widget instead of the bundled one? =

Yes — use the `cap_captcha_for_gravity_forms_widget_src` filter to return your preferred script URL.

== Changelog ==

= 1.0.0 =
* Added: Initial release
* Added: Cap CAPTCHA field for the Gravity Forms editor
* Added: Server-side verification of the Cap token on submission
* Added: Global settings page under Forms → Settings → Cap CAPTCHA
