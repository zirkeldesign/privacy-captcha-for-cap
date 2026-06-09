# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - Unreleased

### Added
- Initial release.
- Custom Gravity Forms field "Cap CAPTCHA" registered under *Advanced Fields*.
- Global settings page (Forms → Settings → Cap CAPTCHA) for endpoint URL, site key, secret.
- Server-side token verification against `<endpoint>/<site_key>/siteverify`.
- Filter `cap_captcha_for_gravity_forms_widget_src` to override the widget script URL.
