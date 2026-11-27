# Agent Guidelines

Commit conventions (gitmoji subject, English, no trailers) come from
`~/.claude/CLAUDE.md`. Stack conventions come from the `wp-plugin-dev` skill.
WordPress core release passes are the `wp-core-release` skill.

## Quality gate

```sh
composer format:test && composer phpstan && composer test
bun run test:js                      # Node tests for the front-end scripts
bun run build:check                  # vendored cap-widget/WASM up to date
composer pcp                         # Plugin Check on the BUILT zip, throwaway WP
WP_VERSION=7.1-RC3 composer pcp      # ... against a specific core
```

`composer test` must print a test count. Silence plus exit 0 means the suite
never ran.

The integration suite needs a Gravity Forms copy you hold a licence for and is
not wired into CI:

```sh
CAP_GF_SOURCE=/path/to/gravityforms composer test:integration:setup
composer test:integration
```

Point `CAP_WP_ROOT` and `CAP_WP_VERSION` elsewhere to provision a second fixture
on a different core without disturbing the cached `.wp-integration` one.

## Rules for every check

Each of these was a bug that shipped. Go through them for a new integration and for any change to when a token is verified.

- **Protect the action, not the form.** List every way a request reaches the handler behind the form: the form's POST, admin-ajax, REST and the Store API (versioned, unversioned, batch), XML-RPC, other plugins calling the same function. For each, either the check runs or the readme says it does not. 1.8.1 claimed to protect the WooCommerce checkout and never saw a Store API order.
- **No field of the request decides whether the check runs**, unless the handler itself refuses the request without that field. Read the handler's source to find out, do not go by what the form sends. 1.8.1 skipped the login check when `wp-submit` was missing, and core logged the user in anyway. `tests/Unit/RequestFieldGateTest.php` lists every field an integration reads and fails on a new one.
- **Write the test from the handler's side:** the smallest request it accepts, without a token, must be refused.
- **A token is single-use.** Verify it as late as possible, after the host plugin's own validation, so a form with a field error keeps its token. On a form that stays on the page (AJAX), renew the token after every failure the script cannot place.
- **Test against what the Cap server really answers**, not against an assumed response. Its status codes and bodies for a refused token, a rate limit and an internal error differ, and only an unreachable server may fail open.
- **A fake in a test has to behave like the real thing.** A form stub whose `requestSubmit()` does not fire `submit` hid an endless resubmit loop.
- **A release cut from a tag goes back into `main` the same day.** 1.8.2 was not, and `main` kept the hole.

## Surfaces to smoke test

Static checks never execute the plugin. After any change to asset loading, and on
every WordPress core release, confirm the widget still renders on:

| Surface | Where | Note |
| --- | --- | --- |
| Login | `/wp-login.php` | **The fragile one.** `WP_Script_Modules::add_hooks()` does not run on wp-login, so `Asset/Enqueuer` prints the modules from `login_footer`. Grep the response for `type="module"` — a missing widget here is silent. |
| Registration | `/wp-login.php?action=register` | needs `users_can_register` |
| Multisite signup | `/wp-signup.php` | needs a network with registration on. Both steps (account, then site) show the widget and each needs its own token |
| Lost password | `/wp-login.php?action=lostpassword` | a failed check answers only with the CAPTCHA error, for known and unknown accounts alike |
| Comments | any single post | |
| WooCommerce Checkout block | the checkout page of a block shop, in all three modes | The widget must still stand above "Place order" once the block has loaded: WooCommerce rebuilds the markup in the browser. An order without a token shows the CAPTCHA error and resets the widget. With a gateway that has express buttons on, product and cart pages must load `woocommerce-express-pass.js`, and an express order must pass twice in a row |
| Checkout block layout across themes | `scripts/checkout-block-themes.js`, pasted into the console on the checkout page of a test shop | Run after any change to the block rules in `assets/css/cap-captcha.css` and on every WooCommerce release. For each theme at 375 and 1280 pixels it must print `gap 24 \| below button 36 \| summary above \| page height +0`. The widget is printed in front of the actions block with `render_block_woocommerce/checkout-actions-block`; markup placed inside that block is thrown away when WooCommerce builds it in the browser, so the place above the button on narrow screens is CSS. The margins are unlayered on purpose: a theme reset such as OceanWP's `div { margin: 0 }` zeroes layered ones |
| WooCommerce | checkout, order-pay (`/checkout/order-pay/<id>/?pay_for_order=true&key=...`), login, registration, lost password | needs WooCommerce active. A failed order-pay check redirects back to the page with the CAPTCHA error |
| Gravity Forms | field settings panel in the form editor | the integration suite covers submission flow. With the REST API v2 on, `POST /wp-json/gf/v2/forms/<id>/submissions` to a protected form must fail without `cap-token` |
| Contact Form 7 | a form with the automatic mode on | |
| Fluent Forms | a form containing the "Privacy CAPTCHA for Cap" field | CI runs the real-plugin suite. Check inline, floating and programmatic mode, and two forms on one page: tokens are single-use and per form. Hook contracts and setup: `docs/fluent-forms-integration.md` |
| wpDiscuz | any single post with wpDiscuz active, main form and a reply form | CI runs the real-plugin suite (`tests/Integration/WpDiscuzFlowTest.php`, posts through PHP's built-in server). Check all three modes, a reply form opened after load, and two comments in a row from one page: the token is spent after each post. |
| Ultimate Member | the UM login, register and password reset pages | CI runs the real-plugin suite (`tests/Integration/UltimateMemberFlowTest.php`). Check all three modes; in floating mode the UM submit button is the trigger on login and register, the reset form keeps its own. A failed login must show only the CAPTCHA error, never "Password is incorrect". |
| Elementor Pro | a page with a form widget containing the "Privacy CAPTCHA for Cap" field, and the field in the editor's form preview | No real-plugin suite: needs a licensed copy. Post to `admin-ajax.php` with `action=elementor_pro_forms_send_form`, `post_id`, `form_id` and `form_fields[...]`: without `cap-token` a protected form must answer with an error on the CAPTCHA field. Load the page twice before judging it: Elementor caches widget HTML, and the first view always works. Check all three modes, a form inside a popup, and a second attempt after a field error. |
| Settings | Settings → Privacy CAPTCHA, incl. "Test connection" | tests the form as it stands, saved or not; a wp-config constant wins over its field |

Minimum config that makes the widget render — `isConfigured()` requires all three:

```sh
wp option update cap_captcha_settings --format=json '{
  "endpoint_base":"https://cap.example.test","site_key":"abc123","secret_key":"deadbeef",
  "display_mode":"inline","wasm_source":"bundled",
  "integrations":{"login":true,"comments":true,"registration":true}}'
```

Seeding the wrong keys (`endpoint` rather than `endpoint_base`) yields a clean,
meaningless "no widget rendered".
