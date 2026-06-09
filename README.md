# Cap CAPTCHA for Gravity Forms

WordPress plugin that adds a [Cap](https://trycap.dev/) CAPTCHA field to Gravity Forms with server-side verification against your self-hosted Cap server.

## Requirements

- WordPress 6.5+ (uses `wp_enqueue_script_module`)
- PHP 8.3+
- Gravity Forms 2.10+
- A reachable Cap server

## Installation (development)

```sh
composer install
bun install
bun run build   # copies cap-widget into assets/js/vendor/
```

The `assets/js/vendor/cap-widget.*` files are committed so the plugin works
straight from a checkout (and from a WordPress.org zip). Rerun `bun run build`
after bumping the `cap-widget` dependency. `bun run build:check` fails CI if
the vendored files drift from `node_modules/cap-widget`.

## Configuration

1. Activate the plugin.
2. Go to **Forms → Settings → Cap CAPTCHA**.
3. Enter your Cap *endpoint base URL* (e.g. `https://cap.example.com/`), *site key*, and *secret key*.
4. Edit a form, drag the **Cap CAPTCHA** field from the *Advanced Fields* group into your form.

## Filters

- `cap_captcha_for_gravity_forms_widget_src` — override the script URL used to load the Cap web component. Default: the plugin-bundled copy under `assets/js/vendor/cap-widget.js`.
- `cap_captcha_for_gravity_forms_wasm_url` — return a URL to set `window.CAP_CUSTOM_WASM_URL`. Use this for fully air-gapped deployments so the widget loads its WASM bundle from your own server instead of jsdelivr.

## Scripts

| Command | What it does |
| --- | --- |
| `composer test` | Run Pest unit tests |
| `composer phpstan` | Static analysis |
| `composer format` | Apply Pint formatting |
| `composer format:test` | Check Pint formatting |

## License

GPL-2.0-or-later
