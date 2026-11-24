# WPForms Integration

This guide is for maintaining and debugging the optional WPForms integration. For installation and placement instructions, see the [main README](../README.md#form-placement).

## Scope and files

- [WpForms.php](../src/Integration/WpForms.php) registers rendering, the builder setting, verification, the AJAX error flag and the fail-open mark. [Plugin.php](../src/Plugin.php) loads it only when `WPFORMS_VERSION` is defined and `wpforms()` exists; [Settings.php](../src/Settings.php) exposes the `wpforms` toggle, the filter context and the `wpforms_protect_all` option.
- [wpforms.js](../assets/js/wpforms.js) handles per-form programmatic solves, holds a submit without a usable token, and renews the token after a failed submit. The shared [programmatic.js](../assets/js/programmatic.js) deliberately skips WPForms fields.
- Which forms are protected is decided per form: the builder's **Settings → Spam Protection and Security** panel gets a *Protection* select with *Default*, *Always* and *Never*, stored as `settings[cap_captcha_mode]` in the form data. *Default* follows **Automatically protect every WPForms form** in the integration's options (off by default). Forms saved before the setting existed count as *Default*. The `wpforms` surface toggle and the `cap_captcha_protect` filters still apply on top: with the surface off, no form is protected, *Always* included.
- The stored `wpforms_protect_all` value is kept while WPForms is inactive, since its options drawer is not rendered then and a save would otherwise switch it off.
- Tested with WPForms Lite only. WPForms Pro, its payment integrations and its add-ons (Conversational Forms, Form Pages, Lead Forms, Save and Resume) have not been tested. The Pro entry-meta path is covered by a unit test against a stub.
- AMP pages are not covered: they run none of the widget's JavaScript, so no widget renders there, and a protected form submitted from an AMP page fails the check. Verification never skips AMP requests, because a client could claim to be one.
- WPForms' own CAPTCHA providers (reCAPTCHA, hCaptcha, Turnstile) are left alone. Both can run on one form; they post different fields.

## Host-plugin contracts

The integration was exercised with WordPress 7.1.4 and WPForms Lite 2.0.5.2 from WordPress.org. These are tested versions, not a declared minimum compatibility range.

| WPForms hook | Integration responsibility |
| --- | --- |
| `wpforms_display_submit_before` | Fires inside the form's submit container, right before the button, for every embed (shortcode, block, widget, preview). Render the widget for a protected form, enqueue the assets, and print a hidden error for a widget script that fails to start. Widget ids come from `wp_unique_id()`: WPForms has no instance counter, and the same form twice on a page needs two widgets. In floating mode, start buffering the submit button instead. |
| `wpforms_display_submit_after` | At priority 1, before the PayPal Commerce button at 9, and only for the `submit` button (page breaks fire it with `next`): end the buffer, add the floating attributes to the first `<button type="submit">`, and print the widget before it. Without such a button the widget keeps its own trigger. |
| `wpforms_admin_builder_anti_spam_panel_content` | Add the *Protection* select through `wpforms_panel_field()` and `wpforms_panel_fields_group()`. |
| `wpforms_process` | At priority 5, verify `$_POST['cap-token']` and add a failure to `wpforms()->obj('process')->errors[$form_id]['footer']`, which WPForms shows above the submit button and, for AJAX forms, in the general errors. Return without asking Cap when the form already has errors. |
| `wpforms_ajax_submit_errors_response` | Add `cap_captcha_kept: true` to the response when the token never went to Cap. |
| `wpforms_process_entry_saved` | After a fail-open pass, add a `cap_captcha_fail_open` entry meta row where WPForms stores entries (Pro), and fire `cap_captcha_fail_open_pass` with `form_id` and `entry_id`. WPForms Lite stores no entries; `entry_id` is 0 there. |

`wpforms_process` fires after WPForms' field validation, its honeypot, its token, time-limit and spam checks and its own CAPTCHA, and it fires even when those added errors. A submission they refused is not sent to Cap, so the token stays valid for the corrected try. Priority 5 puts the check before WPForms' Stripe, Square and PayPal Commerce handlers at 10, so a failed check charges nothing; anything later on the hook that refuses the submission leaves a spent token behind, which the script then renews.

The token is read from the top-level `cap-token` field, outside `wpforms[...]`, through `TokenVerifier::verifyCurrentRequest()`. Missing or rejected tokens fail; fail-open applies to an unreachable Cap server only. An unconfigured integration renders no widget and does not block submissions.

## Spent tokens and held submits

Cap tokens are single-use, and an AJAX form stays on the page after a refused submit. The server tells the script which case it is in:

- **`cap_captcha_kept` in the error response**: WPForms refused the form before the check, the token is unspent, the script leaves the widget alone.
- **No flag** on `wpformsAjaxSubmitFailed` (a rejected token, a handler after the check), and every `wpformsAjaxSubmitError`: the script resets the form's `<cap-widget>`, or in programmatic mode solves a new token. Unknown failures renew rather than keep, so a spent token never stays in a form by default.

`wpformsBeforeFormSubmit` fires for AJAX and normal submits alike, and WPForms stops when it is prevented. The script prevents it while the form has no usable token: in programmatic mode until the pending or a new solve finishes (the spent token stays in the field until the new one replaces it), with a visible widget until `widget.solve()` returns one. It then calls `wpforms.formSubmit()` again and lets that one through. A failed solve shows the error and sends nothing.

Non-AJAX forms reload the page after a submit, which renders a fresh widget.

## Floating mode

The form's own submit button is the trigger: the first click opens the check, and the floating script clicks the button again once it has passed, which starts WPForms' normal submit. The button is wired on the server, by buffering it between `wpforms_display_submit_before` and `wpforms_display_submit_after`, as for Ultimate Member and wpDiscuz.

It is not wired by `wpforms.js`, because of the order the scripts run in. `cap-widget.floating.js` is a script module, so it runs once the document is parsed, scans the page for `[data-cap-floating]` then, and afterwards only watches for added nodes, never for changed attributes. `wpforms.js` is a footer script that does its work on jQuery's ready, which fires after the modules have run: attributes it set on an existing button would come too late, and depending on the earlier, synchronous part of a footer script breaks as soon as an optimisation plugin defers it. `floating-autosubmit.js` binds only to widgets marked `data-cap-autosubmit`, which exist only with a trigger of the widget's own.

When no `<button type="submit">` follows the hook (a customisation that replaced the button), the widget keeps its own trigger with the auto-submit helper, so it can still be opened.

## Tests

The PHP unit tests use WordPress stubs; they do **not** require WordPress or WPForms. The JavaScript tests use Node's built-in test runner and mocked browser objects. Neither suite replaces a real browser test.

```sh
composer install
composer test
bun run test:js
```

The real-plugin tests need WordPress and an installed, activated WPForms Lite, but no running Cap server. Non-AJAX submissions run through WPForms' own `process()` in the test process. WPForms accepts an AJAX submission only from a real admin-ajax request (`wp_doing_ajax()`) and ends it with `wp_send_json()`, so those go through PHP's built-in server on port 8897 against the same fixture; a must-use plugin the suite writes for the run fakes Cap's `/siteverify` and can refuse a submission after the check, as a declined payment would.

The following reproduces the pinned CI setup with PHP 8.3, a CLI PHP `memory_limit` of at least 512 MB, WP-CLI, `pdo_sqlite`, `sqlite3`, `curl`, `unzip`, and network access. Use a disposable absolute path outside this checkout: the setup script links this plugin into the fixture, and a fixture inside the checkout makes WordPress resolve WPForms' own plugin path through that link, after which WPForms refuses to load. Its `--fresh` option deletes the fixture directory.

```sh
export CAP_WP_ROOT=/tmp/cap-wpforms-wordpress
export CAP_WP_VERSION=7.1.4
export CAP_WPFORMS_ONLY=1

composer test:integration:setup
wp plugin install wpforms-lite --version=2.0.5.2 --activate --path="$CAP_WP_ROOT"
wp option patch insert cap_captcha_settings integrations wpforms true --format=json --path="$CAP_WP_ROOT"
composer test:integration -- tests/Integration/WpFormsFlowTest.php --fail-on-skipped
```

The setup command resets the settings, so repeat the option patch after rerunning it. `CAP_WPFORMS_ONLY=1` requires WPForms to be active and bypasses the Gravity Forms bootstrap requirement.

The real-plugin tests cover rendering (inline, floating, two forms and the same form twice), the per-form setting against protect-all, missing, rejected and verified tokens, an unreachable Cap with and without fail-open, a submission WPForms refused before the check, the AJAX error flag, a refusal after the check, an accepted AJAX submission, and the builder select. They do not run a browser. [tests.yml](../.github/workflows/tests.yml) runs them on the pinned WordPress and WPForms Lite versions.

## Version updates and debugging

1. Reproduce the failure with the affected WordPress, PHP, and WPForms versions, Lite or Pro. Record the configured display mode and the form's *Protection* setting. Verify the endpoint, site key, secret, integration toggle and protect-all option before diagnosing a missing widget.
2. Inspect the WPForms release for the hooks above and their arguments, `WPForms_Process::process()` (where `wpforms_process` fires relative to the error checks), `ajax_process_errors()`, `Frontend::foot()` and the submit button markup, and the `wpformsBeforeFormSubmit`, `wpformsAjaxSubmitFailed` and `wpformsAjaxSubmitError` events and `wpforms.formSubmit()` in `assets/js/frontend/wpforms.js`.
3. Run the unit, JavaScript, and real-plugin tests. The CI command must report a test count, not just exit successfully.
4. In a real browser, exercise inline, floating and programmatic modes on an AJAX and a non-AJAX form: a missing required field (token kept), a corrected submit, a failure after the check (widget renewed), two forms on one page, and per-form *Never* / *Always* with protect-all on and off. The Node tests do not exercise WPForms' JavaScript or a Cap server.
5. For asset-loading changes, follow the cross-integration smoke-test matrix and quality gates in [AGENTS.md](../AGENTS.md). Record any unavailable Pro or browser checks instead of treating them as passed.
6. When adopting a new WPForms or WordPress release, change the pins in `tests.yml`, rerun the real-plugin suite and applicable smoke checks, and update this guide with the versions actually verified.
