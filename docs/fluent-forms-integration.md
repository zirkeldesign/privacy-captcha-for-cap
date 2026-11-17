# Fluent Forms Integration

This guide is for maintaining and debugging the optional Fluent Forms integration. For installation and placement instructions, see the [main README](../README.md#form-placement).

## Scope and files

- [FluentForms.php](../src/Integration/FluentForms.php) registers the editor field, rendering, verification, and entry cleanup. [Plugin.php](../src/Plugin.php) loads it only when `FLUENTFORM` is defined; [Settings.php](../src/Settings.php) exposes the `fluent_forms` toggle and filter context.
- [fluent-forms.js](../assets/js/fluent-forms.js) handles per-form programmatic solves and AJAX retries. The shared [programmatic.js](../assets/js/programmatic.js) deliberately skips Fluent Forms fields.
- Add **one** Privacy CAPTCHA for Cap field to each regular form that needs protection. Forms without it are not protected. Place it on the final step of a multi-step form and do not conditionally hide it or its container. Conversational forms are not supported: Fluent Forms' converter drops every element it does not know, so converting a regular form to a conversational one removes the CAPTCHA field and leaves that form unprotected.
- Fluent Forms Pro has not been tested. Verification runs on the final submit; Pro features that store data earlier (step-form drafts, save and resume, partial entries) are assumed to bypass it, as they do for Fluent Forms' own CAPTCHAs.
- Duplicate CAPTCHA fields are not supported: Fluent Forms can rename duplicate input names, while verification uses the fixed `cap-token` name. The field is not registered as a general-purpose conditional field.

## Host-plugin contracts

The integration was exercised with WordPress 7.1.4 and the built Fluent Forms 6.2.17 release. These are tested versions, not a declared minimum compatibility range.

| Fluent Forms hook | Integration responsibility |
| --- | --- |
| `fluentform/editor_components` | Add the `cap_captcha` element to Advanced Fields, using the existing `customHTML` editor template and `cap-token` input name. |
| `fluentform/editor_element_settings_placement` | Provide the field's editor settings groups. |
| `fluentform/form_input_types` | Include the element so Fluent Forms' field parser retains its submitted input. |
| `fluentform/render_item_cap_captcha` | Render the configured widget and enqueue the required assets. Widget IDs are unique per rendered instance. |
| `fluentform/render_item_submit_button` | In floating mode, at priority 9, attach a one-off filter on `fluentform/rendering_field_html_<element>` that turns the form's submit button into the widget's trigger. The element name is read from the button's own data, because it differs between forms (`button`, `submit_button`). At priority 99, after Fluent Forms has rendered the button, the filter is removed again, so it cannot reach another form's button. |
| `fluentform/validation_errors` | Verify the token from the parsed submission data whenever the stored form contains the CAPTCHA field. Add errors under `cap-token`. |
| `fluentform/insert_response_data` | Remove `cap-token` from the response before entry storage. |

Whether a form is protected is read from the form's stored `form_fields`, never from the `$fields` argument of `fluentform/validation_errors`. Fluent Forms builds that argument with `getEssentialInputs()`, which drops every field inside a container whose conditional logic is not met, and it evaluates those conditions against the submitted data. Trusting it would let a submitter switch the CAPTCHA off by choosing what to send. The consequence is deliberate: a CAPTCHA field inside a conditionally hidden container still demands a token, so a visitor who cannot see the widget cannot submit. Keep the field outside conditional containers.

Fluent Forms sends AJAX submissions as serialized form data and parses them before validation. Do not switch this integration to `TokenVerifier::verifyCurrentRequest()`: the token is in Fluent Forms' parsed data, not necessarily top-level `$_POST`. Missing or rejected tokens fail verification when configured and protected; fail-open applies to an unreachable Cap server, not to a missing or rejected token. The existing `cap_captcha_require_token` filter can explicitly override the missing-token requirement. An unconfigured integration renders no widget and does not block submissions.

In floating mode the field renders only the hidden widget and the submit button becomes its trigger: the first click opens the check, and the widget's floating script clicks the button again once it has passed, which starts Fluent Forms' normal AJAX submit. The field falls back to its own trigger button, with the auto-submit helper, when no standard submit button follows it: step forms (`stepsWrapper`), a `custom_submit_button` field anywhere in the form, or a `fluentform/is_hide_submit_btn_<id>` filter that is already in place when the field renders. A widget without a trigger could never be opened, so when in doubt keep the fallback.

Cap tokens are single-use. On Fluent Forms AJAX failure or success, the integration resets visible widgets or starts a fresh programmatic solve for the affected form. Programmatic forms must not share a token; submission waits for that form's pending solve, and a solve failure displays an error and permits a later retry. Check the behavior with more than one form on a page when changing this code.

## Tests

The PHP unit tests use WordPress stubs; they do **not** require WordPress or Fluent Forms. The JavaScript tests use Node's built-in test runner and mocked browser objects. Neither suite replaces a real browser test.

```sh
composer install
composer test
bun run test:js
```

The real-plugin tests need WordPress and an **installed, activated, built Fluent Forms plugin**, but no Gravity Forms licence and no running Cap server. The tests stub Cap's `/siteverify` response, create temporary forms and entries, and clean them up. A raw source checkout alone lacks the generated dependencies needed to activate the plugin.

The following reproduces the pinned CI setup with PHP 8.3, a CLI PHP `memory_limit` of at least 512 MB, WP-CLI, `pdo_sqlite`, `sqlite3`, `curl`, `unzip`, and network access. Use a disposable absolute path: the setup script links this plugin into the fixture and overwrites its CAPTCHA settings. Its `--fresh` option deletes the fixture directory.

```sh
export CAP_WP_ROOT=/tmp/cap-fluent-wordpress
export CAP_WP_VERSION=7.1.4
export CAP_FLUENT_ONLY=1

composer test:integration:setup
wp plugin install fluentform --version=6.2.17 --activate --path="$CAP_WP_ROOT"
wp option patch insert cap_captcha_settings integrations fluent_forms true --format=json --path="$CAP_WP_ROOT"
composer test:integration -- tests/Integration/FluentFormsFlowTest.php --fail-on-skipped
```

The setup command resets the settings, so repeat the option patch after rerunning it. On a fixture where `fluent_forms` is already present, use `wp option patch update` instead of `insert`. `CAP_FLUENT_ONLY=1` requires Fluent Forms to be active and bypasses the Gravity Forms bootstrap requirement; select the Fluent Forms test file as shown. Without that flag the existing integration suite requires Gravity Forms.

The real-plugin tests cover editor registration, rendering, missing and rejected tokens, a verified submission, entry token removal, and an unprotected form. They do not run a browser. [tests.yml](../.github/workflows/tests.yml) runs them on the pinned WordPress and Fluent Forms versions, and separately runs the JavaScript tests. CI installs the public WordPress.org release; nothing of Fluent Forms is bundled in this plugin.

## Version updates and debugging

1. Reproduce the failure with the affected WordPress, PHP, and Fluent Forms versions. Record versions and the configured display mode. Verify the endpoint, site key, secret, integration toggle, and field placement before diagnosing a missing widget.
2. Inspect the actual Fluent Forms release or source for the hook names and argument order above, its field parser and AJAX serialization, editor template and duplicate-name handling, and `fluentform_submission_failed` / `fluentform_submission_success` events. Source paths and APIs may change between releases.
3. Run the unit, JavaScript, and real-plugin tests. Confirm a protected form rejects missing and rejected tokens, accepts a verified token, and does not save it; also confirm a form without the field remains unblocked. The CI command must report a test count, not just exit successfully.
4. In a real browser, exercise inline, floating, and programmatic modes; submit, provoke a failed submission, and retry with a fresh token. Check two forms on one page, field error placement, and the supported multi-step placement. The Node tests do not exercise Fluent Forms' actual JavaScript or a Cap server.
5. For asset-loading changes, follow the cross-integration smoke-test matrix and quality gates in [AGENTS.md](../AGENTS.md), including the fragile WordPress login page. Record any unavailable licensed-plugin or browser checks instead of treating them as passed.
6. When adopting a new Fluent Forms or WordPress release, change the pins in `tests.yml`, rerun the real-plugin suite and applicable smoke checks, and update this guide with the versions actually verified.