# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Ultimate Member integration (`ultimate_member` context, off by default, listed with the form plugins). The widget renders above the submit button of the UM login and registration forms (`um_after_login_fields`, `um_after_register_fields`) and on the password reset form (`um_after_password_reset_fields`). The token is verified on `um_submit_form_errors_hook_login`, `um_submit_form_errors_hook__registration` and `um_reset_password_errors_hook`, and a failure is added as a UM form error. When the login check fails, UM's own password check is taken off for that request: it runs even after another handler has added an error, and the page would otherwise say whether the password was right. The same goes for UM's reset validator, which would otherwise count every tokenless request against the user's reset limit and so let anyone lock a user out of resetting. As a second line that does not depend on UM's function names, a last callback on each error hook leaves only the CAPTCHA error, so a failed check tells nothing about the password, a taken username or email address, or the reset limit, and drops the user id UM keeps for a correct password, which its status check would otherwise answer with a redirect for pending or blocked accounts. A login that fails the check still fires `wp_login_failed`, as on wp-login.php, so lockout plugins count it. On the login form the error appears in UM's own notice above the fields, on registration and reset under the widget. In floating mode the UM submit button becomes the trigger on login and registration; the reset form keeps a separate trigger. The token is removed from the `submitted` user meta UM saves on registration, and registrations that only passed through fail-open get the `cap_captcha_fail_open` user meta. Profile and account forms are not covered.
- "Lost password" under WordPress protects the lost-password form on `wp-login.php` (context `lost_password`, off by default). Until now anyone could make WordPress send reset emails to any account. A failed check answers with the CAPTCHA error alone, so it does not reveal whether an account exists.
- Cap 4 support. The bundled widget is now cap-widget 0.1.63: Cap 4 rejects older widgets on site keys that block on behavior or on automated interactions, and flags the solves it still accepts from them.
- "Isolate the Cap check from the page" under Privacy, on by default. Since cap-widget 0.1.59 the script the Cap server sends to tell browsers from bots runs with the rights of the page, on every protected form and on `wp-login.php`, unless the widget is told to isolate it. With the setting on it runs in a frame that cannot read the page, as it always did up to 1.7.0. Site keys that block on behavior or on automated interactions reject an isolated check: turn the setting off for those, ideally only with a Cap server you run yourself. Also available as the `cap_captcha_isolate_frame` filter.
- "Test connection" checks the secret key and reports what the Cap server answers when it refuses a key: a secret or an API key that Cap 4 no longer accepts now says so instead of "did not return a valid response".
- "Test connection" warns about an API key that can read other site keys and about a display mode the site key cannot accept, and fails when the site key rejects an isolated check.
- The status panel says when the Cap server no longer accepts the secret of the site key.
- The widget's "Blocked by this page" and "Troubleshoot" labels are translatable.

### Changed

- "Test connection" no longer needs an API key. Without one it passes on the site key and secret alone and says that the statistics stay off.
- "Test connection" tests the form as it stands, before it is saved. One exception: while the secret or the API key is defined in `wp-config.php`, it keeps to the saved endpoint and says so, so that a key hidden from administrators is never sent to an address that was only typed in.
- "Admin API key" is now "API key". The option and the `CAP_CAPTCHA_ADMIN_API_KEY` constant keep their names.
- The message about a solver file the Cap server does not serve names the Cap 4 dashboard setting.

### Fixed

- The status panel kept showing the previous site key for up to five minutes after the settings were saved.

## [1.8.1] - 2026-11-17

### Added
- readme FAQ entries: private security reporting via GitHub Security Advisories, and where to report bugs and ideas.
- The description lists the other zirkel.design plugins on wordpress.org.

## [1.8.0] - 2026-11-17

### Added

- wpDiscuz integration (`wpdiscuz` context). The widget renders on the `wpdiscuz_submit_button_before` hook inside the main form and the hidden reply template, with the form's unique id in the widget id, so every reply form wpDiscuz clones gets its own widget. The token is verified on `wpdiscuz_before_wp_new_comment`; a failure answers wpDiscuz' AJAX call with its own phrase key, which the integration adds to `wpdiscuz_js_options`, so the visitor sees a translated message. Fail-open passes are marked with the `cap_captcha_fail_open` comment meta, as for core comments. In floating mode the wpDiscuz submit button becomes the trigger; in programmatic mode `assets/js/wpdiscuz.js` solves a token per form and holds an early click until it is ready. After every post, successful or not, the widget is reset or a new token is solved, since the token is spent. Direct posts to `wp-comments-post.php` on posts that use a wpDiscuz form are verified too unless the Comments integration already does it, the same path wpDiscuz' own CAPTCHA guards. wpDiscuz' inline feedback forms are not covered.

### Changed

- Fluent Forms, floating mode: the form's own submit button now opens the check and sends the form once it has passed, as in Gravity Forms. Before, the field added a second button above the submit button, and both sent the form. Step forms, forms with a custom submit button field and forms whose submit button is hidden by a filter keep the separate button, because they render no standard submit button after the field.
- The display modes are named by what they do: "Visible in the form (inline)", "Opens on submit (floating)" and "Invisible, solved in the background (programmatic)". The settings page and the Gravity Forms field now use the same three labels. The stored values and the filter names are unchanged.

## [1.7.0] - 2026-11-16

### Added

- Fluent Forms integration, contributed by Bernhard Kaindl. Add the "Privacy CAPTCHA for Cap" field from Advanced Fields to each regular form that should be protected; forms without the field stay untouched. All three display modes work, tokens are verified from Fluent Forms' parsed submission and never stored with the entry, and each form on a page gets its own single-use token that is renewed after every AJAX submission. Conversational forms are not supported. A form is treated as protected whenever its stored fields contain the CAPTCHA, also when the field sits inside a conditionally hidden container, so keep it outside conditional containers.

### Security

- With fail-open enabled, a submission could pass without a valid token when the sender made the verification request itself fail, for example with an oversized token that a proxy in front of the Cap server refused. Such a response looked like an outage. Oversized tokens are now rejected before the request, and a client error without a Cap answer counts as a rejection. Sites with fail-open disabled, the default, were not affected.

## [1.6.2] - 2026-10-28

### Fixed

- `Contributors` in `readme.txt` named `dsturm`, which WordPress.org resolves to an unrelated account (@dsturm-1). It now lists `zirkeldesign, d.sturm`: the zirkel.design organisation account and Daniel Sturm's login.

## [1.6.1] - 2026-10-23

### Fixed

- The CAPTCHA that is added to Gravity Forms automatically now takes a full row. Themes that lay form fields out in a grid squeezed it into a single column, so the widget stuck out of the form.

## [1.6.0] - 2026-10-22

### Changed

- The status panel shows the site key's protocol (sha256-pow, hashwx or rsw) and the difficulty figure that belongs to it. Contributed by [@loxK](https://github.com/loxK) in [#27](https://github.com/zirkeldesign/privacy-captcha-for-cap/pull/27).
- "Test connection" names the key's protocol and, when the WASM source is the Cap server, checks that the server actually serves the solver file the protocol needs. Contributed by [@loxK](https://github.com/loxK) in [#27](https://github.com/zirkeldesign/privacy-captcha-for-cap/pull/27).

## [1.5.1] - 2026-10-22

### Fixed

- The login screen no longer loads the CAPTCHA script when only registration is protected (and the registration screen not when only login is protected). The script ran there without its settings and logged a "wasm load failed" warning.

## [1.5.0] - 2026-09-25

### Added

- **Gravity Forms Orbital presets for the widget.** `assets/css/cap-captcha.css` maps Orbital's `--gf-*` control tokens onto cap-widget's `--cap-*` custom properties (font, colours, border, radius, height, padding, checkbox, focus ring, spinner, invalid border), so the CAPTCHA looks like the fields around it without theme CSS. Form styles picked in the block editor follow automatically, since Gravity Forms prints them as inline `--gf-*` tokens on the same wrapper. Verified on Gravity Forms 3.1.2 with a customised Orbital form (widget and text input computed to the same height, border colour, radius, background, colour and font; widget checkbox and form checkbox to the same size, border and radius), on stock Orbital, and with block-editor colours applied.
  - The presets sit on `:where(.gform-theme--framework)`, the wrapper, not on `cap-widget`. A declaration on the element itself would beat anything a theme sets on an ancestor regardless of layer or specificity. As it is, themes override on `cap-widget`, `.cap-captcha` or the wrapper; only `:root` no longer reaches.
  - `--cap-font` falls back to the token `0`, not `inherit`. Orbital ships `--gf-font-family-base: initial`, and its fields inherit the page font because their font-family becomes invalid at computed-value time. `0` does the same inside the shadow root. `inherit` does not: a CSS-wide keyword in a custom property applies to the custom property itself, which left `--cap-font` empty and the widget on its own system font stack.
  - The legacy Gravity Forms theme has neither the class nor the tokens and is unaffected.
- `tests/Unit/OrbitalPresetsTest.php` pins both sides of the mapping: every `--cap-*` property set is read by the vendored widget, the presets are never declared on `cap-widget`, and every `--gf-*` token exists in Gravity Forms. The last check runs only where Gravity Forms is installed (the integration fixture), since it is commercial and not available in CI.
- **French translation (`fr_FR`).** Contributed by [@loxK](https://github.com/loxK) (Laurent Dinclaux, Gecka) in [#23](https://github.com/zirkeldesign/privacy-captcha-for-cap/pull/23), their first contribution to the plugin. Thank you! All 131 strings, tested on a French site with the settings page, the dashboard widget and the inline widget in a Contact Form 7 form. The WASM source label that changed in 1.4.0 was added on top.

## [1.4.0] - 2026-09-25

### Fixed

- **Site keys using the `hashwx` protocol could not be solved.** Cap Standalone 3.1 creates new site keys with `protocol: "hashwx"` by default (`keyDefaults` in `standalone/src/server.js`). The vendored cap-widget 0.1.56 only knew `sha256-pow`, `rsw` and `instrumentation` and aborted with *"unsupported format-2 protocol 'hashwx'"*, so every form protected by such a key was unsubmittable. cap-widget is now 0.1.58, which adds the hashwx solver. Verified end to end against a freshly created default key on Cap 3.1.14 (Gravity Forms submit accepted, counted as verified on the Cap server), and against sha256-pow keys on both 3.1.14 and an older 3.x instance.

### Added

- `assets/wasm/hashwx.wasm`, vendored verbatim from `@cap.js/wasm@0.0.8` by `scripts/build-assets.mjs` alongside `cap_wasm_bg.wasm`. hashwx has **no JS fallback**: if the widget cannot load this file it fails the challenge outright, and since the build strips the widget's jsdelivr fallback, `window.CAP_CUSTOM_HASHWX_URL` must always be set. `Asset\Enqueuer` now emits it next to `CAP_CUSTOM_WASM_URL`.
- `Settings::getSelfHostedHashwxUrl()`, resolved through the same *WASM source* setting as the sha256 solver (bundled copy, or `<endpoint>/assets/hashwx.wasm` on the Cap server). The widget only fetches it when a challenge actually uses hashwx.
- `cap_captcha_hashwx_url` filter, the hashwx counterpart of `cap_captcha_wasm_url`.

### Changed

- `@cap.js/wasm` 0.0.7 to 0.0.8. `cap_wasm_bg.wasm` is byte-identical.
- The *Cap server* WASM source label now names the `/assets/` directory instead of a single file, since both solvers are loaded from there.
- Kept the `cap-widget` package name rather than switching to `@cap.js/widget`: upstream publishes the same build under both names (`widget/build.js`), and the docs still install `cap-widget`.

## [1.3.2] - 2026-08-13 (never tagged; shipped as part of 1.4.0)

### Changed

- **`Tested up to: 7.1`.** Verified against WordPress 7.1-RC3, not asserted: Plugin Check on the built zip reports 0 errors and 0 warnings, the Gravity Forms integration suite passes against real Gravity Forms 2.10.5 on 7.1, and the widget still renders on `wp-login.php`, the registration form and the comment form — the `login_footer` script-module printing (see `Asset/Enqueuer`) being the fragile part, since `WP_Script_Modules::add_hooks()` does not cover wp-login. None of the 7.1 breaking changes apply: the plugin registers no blocks, ships no editor JS, has no `@wordpress/*` dependency, touches neither the media pipeline nor the admin toolbar, and its only jQuery use is core `jQuery` inside the Gravity Forms field-settings panel — not jQuery UI.

### Added

- `bin/plugin-check.sh` (`composer pcp`) — builds the distribution zip and runs the real WordPress.org Plugin Check against **the zip** on a throwaway SQLite-backed WordPress, failing on any error or warning. Replaces the old `composer plugin-check`, which checked whatever happened to be active in an existing install and so never saw the files the dist script adds or `.distignore` removes. `WP_VERSION=7.1-RC3 composer pcp` targets a specific core, so a release candidate can be tested before readme.txt claims compatibility with it.

## [1.3.1] - 2026-08-12

### Fixed
- **Fatal error on Gravity Forms 3.0 and newer.** GF 3.0 made both parameters of `GF_Field::get_field_label()` optional; our override still declared them as required, which PHP rejects as an incompatible signature — every request died with *"Declaration of … must be compatible with GF_Field::get_field_label(\$force_frontend_label = true, \$value = '')"*. Both parameters now carry GF 3's defaults, which is equally valid on GF 2.x (it always passes both arguments). The parent's return value is cast, since `GF_Field::get_field_label()` can return a null label. `php-stubs/gravity-forms-stubs` has no 3.x release, so PHPStan cannot see this drift — `tests/Unit/GfFieldSignatureTest.php` pins the contract instead, and the `GF_Field` stand-in in `tests/bootstrap.php` now mirrors the GF 3 signature so a regression fails to load in the test suite as well.

## [1.3.0] - 2026-08-10

### Fixed
- **Multi-page Gravity Forms were unprotected or unsubmittable.** `gform_validation` fires on every page transition, but `cap-token` is a plain POST field written into the form by `<cap-widget>` — not a form value — and Gravity Forms re-renders the whole form (all pages, inactive ones hidden) on each transition, so the solved token never survives to the final submit. `Validator` demanded a proof on every page regardless. On forms with a preview step (e.g. the Gravity Wiz *GP Preview Submission* perk, which is a page break plus `{all_fields}` on the last page) the CAPTCHA could therefore never be satisfied: fail-closed made the form unsubmittable, fail-open made the CAPTCHA decorative. `Validator` is now page-aware — it requires the proof exactly when the page holding the field was submitted, or on the final submit — and `VerifiedState` carries an earlier page's verification across the rest of the submission via a short-lived transient keyed on GF's `gform_unique_id`.
- **Fail-open no longer waives a missing token.** `TokenVerifier::verifyToken('')` routed an empty token through `failOpen()`, so a site with fail-open enabled accepted *every* tokenless submission on *every* surface — the CAPTCHA became optional. Fail-open now means what `readme.txt` and the settings UI have always said it means: it applies only when the Cap server is genuinely unreachable. The not-configured short-circuit is unchanged.

### Added
- **Integration test suite** (`tests/Integration`, `composer test:integration`) running against a real WordPress + Gravity Forms via `GFFormDisplay::process_form()`, so the paging logic the validator mirrors is GF's own. Six of its twelve cases fail against the pre-fix code. The fixture is provisioned by `scripts/integration-env.sh` (SQLite, no MySQL, no Gravity Perks); Cap is faked through `pre_http_request` so the suite stays offline. Not wired into CI — Gravity Forms is commercial and would need its licence as a repository secret.
- `cap_captcha_require_token($required, $context)` filter — opt a surface out of the empty-token block for flows that legitimately submit without our widget.
- `cap_captcha_gf_field_page($page, $form)` filter — move the auto-injected Gravity Forms field off the last page (useful when that page can be hidden by conditional logic).
- `cap_captcha_gf_verified_ttl($seconds)` filter — how long a verification stays valid across the pages of one submission (default one hour).
- `cap_captcha_gf_skipped_hidden($formId, $fieldId)` action — fires when enforcement is skipped because conditional logic hid the CAPTCHA field.

### Changed
- The CAPTCHA now keeps a margin below itself on `wp-login.php` when something follows it, instead of sitting flush against the submit button.
- `gform_validation` is now registered with two arguments; `GFAPI::submit_form()` and validation-only API calls (`api-submit` / `api-validate`) are no longer gated, since no browser could have solved a challenge.
- Failing Gravity Forms validation now sets `failed_validation_page`, so the visitor lands on the page that actually holds the widget.
- The Login, Registration, and Comments integrations bail out on REST, XML-RPC, WP-CLI and cron requests, and on posts that lack the marker field of the form we render into (`wp-submit` / `comment_post_ID`). Without this the fail-closed change above would have broken XML-RPC logins, the REST comments endpoint, and third-party login forms.
## [1.2.5] - 2026-08-10 (never tagged; shipped as part of 1.3.0)

### Fixed
- **Deactivating a form plugin could silently disable its protection.** A card whose plugin is inactive renders as a disabled checkbox, and disabled checkboxes are not submitted — but the sanitize callback read the POST alone, so saving *any* setting stored that integration as off. Deactivate Gravity Forms for an upgrade, save an unrelated setting, reactivate it, and the forms came back unprotected with the box unticked and no warning. Integrations whose plugin is unavailable now keep their stored value, matching how the `wp-config` constant-backed keys already behaved.
- The Gravity Forms integration no longer defaults to **on**. That was a leftover from when the plugin was Gravity Forms-only, and it left the card ticked on installs with no Gravity Forms at all. It now defaults to off like every other surface.

## [1.2.4] - Unreleased

### Changed
- **The plugin no longer needs Composer to run.** `composer.json` requires nothing but PHP itself, so `vendor/` only ever held Composer's autoloader mapping `ZirkelDesign\CapCaptcha\` onto `src/`. That mapping now lives in `autoload.php`, used whenever `vendor/autoload.php` is absent. Consequences: a plain copy of the repository runs as-is (so the 1.2.3 "missing autoloader" notice is gone — it can no longer happen), `vendor/` is excluded from the package via `.distignore`, and `deploy.yml` and the `dist` script drop their `composer install --no-dev` steps. The fallback keeps deferring to Composer when it is present, so a future runtime dependency still works — `AutoloadMappingTest` fails if one is added without revisiting the packaging.
- Release builds are attached to the GitHub release. Previously the Releases page offered only GitHub's auto-generated source archive, which is not an installable plugin.

### Fixed
- Refreshed the vendored `pako_inflate.min.js`, which had been left at 2.1.0 when `9d04c10` bumped the pinned dependency to 2.2.0. The shipped copy now matches `package.json` again, as `bun run build:check` requires.
- `.distignore` excluded `dist/` but not the integration-test WordPress install, and the pattern for Composer's `vendor/` also matched `assets/js/vendor/`. Both were caught by building and inspecting the zip: the first inflated it from 92 KB to 28 MB, the second silently dropped `cap-widget.js` and would have shipped a plugin with no widget at all.

## [1.2.3] - Unreleased

### Fixed
- **The plugin did nothing unless Gravity Forms was active** ([#11](https://github.com/zirkeldesign/privacy-captcha-for-cap/issues/11)). The entry file gated `Plugin::boot()` behind `class_exists('GFForms') || class_exists('GFAPI')` — a leftover from before `01bc7e6` rebuilt the Gravity Forms-only plugin into a multi-surface one. Without Gravity Forms nothing booted: no **Settings → Privacy CAPTCHA for Cap** page, and no comments, login, registration or WooCommerce protection either. `Plugin::boot()` has always asked each integration whether its host plugin is present (`Integration::isAvailable()`), so the outer gate was both wrong and redundant; it and its "requires Gravity Forms" admin notice are gone. This matches what `readme.txt` already documented: *"Gravity Forms 2.5+ (only if you enable the Gravity Forms integration)"*.
- A copy installed straight from the source repository (which ships no `vendor/`, so nothing is autoloadable) now shows an explanatory admin notice instead of dying with a class-not-found fatal. The release zips from WordPress.org and the GitHub Releases page are unaffected — they bundle the autoloader.

## [1.2.2] - Unreleased

### Fixed
- An enabled surface no longer blocks submissions when the plugin is **not configured**. `TokenVerifier::verifyToken()` previously routed the not-configured case through `failOpen()` (fail-closed by default), so with e.g. the Login surface on but no endpoint/site key/secret set, logins failed with "CAPTCHA verification failed" while no widget was even rendered. It now returns early as a pass — protection stays off until configured.

## [1.2.1] - Unreleased

### Fixed
- WooCommerce checkout **account creation** no longer fails the CAPTCHA. `woocommerce_registration_errors` fires during checkout (via `wc_create_new_customer()`) as well as on the My Account register form, but the widget only renders on the latter; `verifyRegistration` now bails unless the register form's `woocommerce-register-nonce` is present (mirroring `verifyLostPassword`).
- The Login integration's WooCommerce-login skip guard checked a non-existent `woocommerce-login` field; corrected to `woocommerce-login-nonce`, so core Login verification no longer runs on WooCommerce My Account logins (which previously could block them when `woocommerce_login` was off but the Login surface was on).

## [1.2.0] - Unreleased

### Added
- **Per-surface fail-open.** Each surface can override the global fail-open with **Default / Fail-open / Fail-closed** (e.g. let logins through during a Cap outage but always require a valid proof on contact forms). New `cap_captcha_fail_open($open, $context)` filter.
- **`CapVerifier::check()`** returns `VerificationResult::{Verified,Rejected,Unreachable}`. Fail-open now applies **only** when Cap is unreachable (or there is no token) — an actively rejected token always blocks, even on a fail-open surface.
- **Fail-open annotations.** Submissions accepted during an outage are tagged `cap_captcha_fail_open = 1`: Gravity Forms entry meta, WooCommerce order meta + order note, comment meta, registration user meta. A `cap_captcha_fail_open_pass($context, $data)` action fires for custom handling.

### Changed
- `TokenVerifier` threads the surface `$context` through every integration and exposes `wasLastFailOpen()`.
- The Gravity Forms validator now defers an empty token to the surface's fail-open policy instead of always blocking.

## [1.1.0] - Unreleased

### Added
- **Contact Form 7** integration: injects the widget via `wpcf7_form_elements` and rejects unverified submissions through the `wpcf7_spam` gate.
- **CF7 placement control**: a `[cap_captcha]` form-tag for manual placement and a mode setting — **Automatic** (all forms, with per-form opt-out via the `cap_captcha: off` Additional Setting) or **Manual** (only forms carrying the tag). Verification is scoped to protected forms, so legally required / accessibility-sensitive forms left without the CAPTCHA are never blocked.
- **GF placement control**: a global "protect all Gravity Forms" setting and a per-form **Default / Always / Never** override in the form settings. Auto-protected forms get a synthetic `cap_captcha` field injected at runtime (via `gform_pre_render` / `gform_pre_validation` / `gform_pre_submission_filter`) so render and validation reuse the existing field path.
- **WooCommerce My Account** surfaces: login (`woocommerce_login`), registration (`woocommerce_registration`), and lost password (`woocommerce_lost_password`), each an independent toggle alongside checkout (`woocommerce_checkout`). A WooCommerce **master toggle** (`woocommerce`) gates all four — surfaces are picked in the master card's options disclosure in settings. The legacy `woocommerce` value maps to master-on + `woocommerce_checkout`.
- **Dashboard widget** (`Admin\DashboardWidget`) reusing the extracted `Status\StatusPanel` to show cached Cap server stats with a link to settings.
- **Per-surface protection model**: `Settings::isProtected($context)` gates every render and verification, exposed via the `cap_captcha_protect` and `cap_captcha_protect_{context}` filters. Granular settings toggles for all nine surfaces.

### Changed
- Integrations now register unconditionally when available and gate each render/verify on `isProtected()`, so the per-surface toggles and filters control behaviour at request time.
- `TokenVerifier` memoises verification per token within a request, preventing a single-use token from being redeemed twice when multiple hooks fire (e.g. WooCommerce login).
- Extracted the status panel rendering out of `Settings` into `Status\StatusPanel`.

### Fixed
- The Login integration no longer runs on WooCommerce My Account logins (detected via the `woocommerce-login` nonce), which previously could block them; the `woocommerce_login` surface owns that form.

### Migration
- The legacy single `woocommerce` toggle maps to `woocommerce_checkout` automatically.

## [1.0.0] - Unreleased

### Added
- Initial release as **Privacy CAPTCHA for Cap** (renamed from "Cap CAPTCHA for Gravity Forms").
- Top-level **Settings → Privacy CAPTCHA for Cap** page with per-integration toggles.
- **Comments** integration (`comment_form_after_fields` + `preprocess_comment`).
- **Login** integration (`login_form` + `wp_authenticate_user`).
- **Registration** integration (`register_form` + `registration_errors`).
- **WooCommerce checkout** integration (auto-detected, only loads when WC is active).
- **Programmatic display mode** — auto-solves the challenge in the background, no user interaction.
- **WASM source** setting (bundled / your own Cap server) with bundled as the default — both served from your own infrastructure.
- **Fail-open** behavior toggle for resilience during Cap-server outages.
- **`CAP_CAPTCHA_SECRET_KEY`** constant override so the secret can live in `wp-config.php`.
- Fully self-hosted front-end assets: bundled `@cap.js/wasm@0.0.7` plus the `pako` decompression library and the cap-widget script, all served from the plugin. The jsdelivr fallback URLs baked into the upstream widget bundle are stripped at build time and the plugin sets `window.CAP_PAKO_URL` / `window.CAP_CUSTOM_WASM_URL` to local copies, so no third-party CDN is contacted at runtime.
- German (`de_DE`) translations for the new settings strings.
- Architecture refactor: shared `Asset\Renderer`, `Asset\Enqueuer`, `Verification\TokenVerifier`, and an `Integration\Integration` contract.
