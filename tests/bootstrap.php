<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';

if (! defined('CAP_CAPTCHA_VERSION')) {
    define('CAP_CAPTCHA_VERSION', '1.0.0-test');
}
if (! defined('CAP_CAPTCHA_FILE')) {
    define('CAP_CAPTCHA_FILE', __FILE__);
}
if (! defined('CAP_CAPTCHA_DIR')) {
    define('CAP_CAPTCHA_DIR', dirname(__DIR__).'/');
}
if (! defined('CAP_CAPTCHA_URL')) {
    define('CAP_CAPTCHA_URL', 'http://localhost/wp-content/plugins/cap-captcha/');
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(preg_replace('/[\r\n\t]+|\s+/u', ' ', $value) ?? $value);
    }
}

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__cap_options'][$name] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $name, mixed $value): bool
    {
        $GLOBALS['__cap_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('add_option')) {
    function add_option(string $name, mixed $value = ''): bool
    {
        if (array_key_exists($name, $GLOBALS['__cap_options'] ?? [])) {
            return false;
        }

        $GLOBALS['__cap_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['__cap_options'][$name]);

        return true;
    }
}

/**
 * Reset the in-memory wp_options store between tests.
 */
function cap_reset_options(): void
{
    $GLOBALS['__cap_options'] = [];
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['__cap_filters'][$hook_name][] = ['cb' => $callback, 'args' => $accepted_args, 'priority' => $priority];

        return true;
    }
}

if (! function_exists('did_action')) {
    function did_action(string $hook_name): int
    {
        return (int) ($GLOBALS['__cap_did_actions'][$hook_name] ?? 0);
    }
}

if (! function_exists('has_block')) {
    function has_block(string $block_name, mixed $post = null): bool
    {
        return in_array($block_name, $GLOBALS['__cap_page_blocks'] ?? [], true);
    }
}

if (! function_exists('wc_get_page_id')) {
    function wc_get_page_id(string $page): int
    {
        return 42;
    }
}

if (! function_exists('wc_notice_count')) {
    function wc_notice_count(string $notice_type = ''): int
    {
        return $notice_type === 'error' ? (int) ($GLOBALS['__cap_wc_error_notices'] ?? 0) : 0;
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $hook_name, callable $callback, int $priority = 10): bool
    {
        $GLOBALS['__cap_filters'][$hook_name] = array_values(array_filter(
            $GLOBALS['__cap_filters'][$hook_name] ?? [],
            static fn (array $entry): bool => $entry['cb'] !== $callback,
        ));

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
    {
        foreach ($GLOBALS['__cap_filters'][$hook_name] ?? [] as $entry) {
            $passArgs = array_slice($args, 0, max(0, $entry['args'] - 1));
            $value = ($entry['cb'])($value, ...$passArgs);
        }

        return $value;
    }
}

/**
 * Reset the in-memory filter registry between tests.
 */
function cap_reset_filters(): void
{
    $GLOBALS['__cap_filters'] = [];
}

if (! function_exists('add_action')) {
    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return add_filter($hook_name, $callback, $priority, $accepted_args);
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$args): void
    {
        $GLOBALS['__cap_actions'][$hook_name][] = $args;
    }
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-salt-'.$scheme;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['__cap_transients'][$key] = $value;

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return $GLOBALS['__cap_transients'][$key] ?? false;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['__cap_transients'][$key]);

        return true;
    }
}

/**
 * Reset the in-memory transient store between tests.
 */
function cap_reset_transients(): void
{
    $GLOBALS['__cap_transients'] = [];
}

if (! class_exists('GF_Field')) {
    /**
     * Minimal stand-in for Gravity Forms' field base class — enough for
     * ZirkelDesign\CapCaptcha\Integration\GravityForms\Field to be constructed
     * with a property bag, which is all the auto-injection path needs.
     *
     * The method signatures mirror Gravity Forms 3.0 so that an incompatible
     * override in our field class fails to load here too, rather than only on
     * a live GF 3.x site.
     */
    #[AllowDynamicProperties]
    class GF_Field
    {
        /** @var string|null */
        public $label;

        /** @param array<string, mixed> $data */
        public function __construct(array $data = [])
        {
            foreach ($data as $key => $value) {
                $this->{$key} = $value;
            }
        }

        public function is_form_editor(): bool
        {
            return false;
        }

        /**
         * @param  bool  $force_frontend_label
         * @param  int|string  $value
         */
        public function get_field_label($force_frontend_label = true, $value = ''): string
        {
            return (string) $this->label;
        }
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, array<int, string>> */
        public array $errors = [];

        public function __construct(string $code = '', string $message = '')
        {
            if ($code !== '') {
                $this->add($code, $message);
            }
        }

        public function add(string $code, string $message = ''): void
        {
            $this->errors[$code][] = $message;
        }

        public function get_error_code(): string
        {
            return array_key_first($this->errors) ?? '';
        }

        /** @return array<int, string> */
        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function has_errors(): bool
        {
            return $this->errors !== [];
        }

        public function remove(string $code): void
        {
            unset($this->errors[$code]);
        }
    }
}

if (! class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 1;
    }
}

if (! function_exists('wp_remote_post')) {
    /**
     * Test stub. Reads from $GLOBALS['__cap_remote_response'] and records the last request in
     * $GLOBALS['__cap_remote_last_request'].
     *
     * @param  array<string, mixed>  $args
     */
    function wp_remote_post(string $url, array $args = []): array|\WP_Error
    {
        $GLOBALS['__cap_remote_last_request'] = ['url' => $url, 'args' => $args];
        $GLOBALS['__cap_remote_post_count'] = ($GLOBALS['__cap_remote_post_count'] ?? 0) + 1;

        $response = $GLOBALS['__cap_remote_response'] ?? ['body' => '{"success":true}'];

        if ($response instanceof \WP_Error) {
            return $response;
        }

        return $response;
    }
}

if (! function_exists('wp_remote_get')) {
    /**
     * Test stub. $GLOBALS['__cap_remote_get_responses'] maps a URL fragment to
     * the response returned for any URL containing it, so one test can answer
     * the stats call and the solver probe differently. Every request lands in
     * $GLOBALS['__cap_remote_get_requests'], in order.
     *
     * @param  array<string, mixed>  $args
     */
    function wp_remote_get(string $url, array $args = []): array|\WP_Error
    {
        $GLOBALS['__cap_remote_get_requests'][] = ['url' => $url, 'args' => $args];

        foreach ($GLOBALS['__cap_remote_get_responses'] ?? [] as $fragment => $response) {
            if (str_contains($url, (string) $fragment)) {
                return $response;
            }
        }

        return ['response' => ['code' => 200], 'body' => ''];
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        if (is_array($response) && isset($response['body'])) {
            return (string) $response['body'];
        }

        return '';
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): int|string
    {
        if (is_array($response) && isset($response['response']['code'])) {
            return (int) $response['response']['code'];
        }

        return '';
    }
}

/**
 * Reset the wp_remote_post and wp_remote_get stubs between tests.
 */
function cap_reset_remote_stub(): void
{
    unset(
        $GLOBALS['__cap_remote_response'],
        $GLOBALS['__cap_remote_last_request'],
        $GLOBALS['__cap_remote_post_count'],
        $GLOBALS['__cap_remote_get_responses'],
        $GLOBALS['__cap_remote_get_requests'],
    );
}

if (! function_exists('wp_enqueue_script_module')) {
    function wp_enqueue_script_module(string $id, string $src = '', array $deps = [], string|false|null $version = false): void
    {
        $GLOBALS['__cap_modules'][] = $id;
    }
}

if (! function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void {}
}

if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, array|bool $args = []): void
    {
        $GLOBALS['__cap_scripts'][$handle] = ['src' => $src, 'deps' => $deps, 'args' => $args];
    }
}

if (! function_exists('wp_unique_id')) {
    function wp_unique_id(string $prefix = ''): string
    {
        static $counter = 0;

        return $prefix.(string) ++$counter;
    }
}

if (! function_exists('wp_print_inline_script_tag')) {
    function wp_print_inline_script_tag(string $data, array $attributes = []): void
    {
        echo '<script>'.$data.'</script>';
    }
}

/** Stands in for the exit in wp_die() and wp_send_json_error(), so a test can assert on it. */
final class CapTestHalt extends RuntimeException
{
    public function __construct(public readonly mixed $data, string $message = '')
    {
        parent::__construct($message);
    }
}

if (! function_exists('wp_die')) {
    function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
    {
        throw new CapTestHalt($args, (string) $message);
    }
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, ?int $status_code = null, int $flags = 0): never
    {
        throw new CapTestHalt($data, 'wp_send_json_error');
    }
}

if (! function_exists('add_comment_meta')) {
    function add_comment_meta(int $comment_id, string $meta_key, mixed $meta_value, bool $unique = false): int
    {
        $GLOBALS['__cap_comment_meta'][$comment_id][$meta_key] = $meta_value;

        return 1;
    }
}

if (! function_exists('wp_kses')) {
    function wp_kses(string $content, mixed $allowed_html): string
    {
        return $content;
    }
}

if (! function_exists('remove_action')) {
    function remove_action(string $hook_name, mixed $callback, int $priority = 10): bool
    {
        $GLOBALS['__cap_removed_actions'][$hook_name][] = $callback;

        return true;
    }
}

if (! function_exists('absint')) {
    function absint(mixed $maybeint): int
    {
        return abs((int) $maybeint);
    }
}

if (! function_exists('add_user_meta')) {
    function add_user_meta(int $user_id, string $meta_key, mixed $meta_value, bool $unique = false): int
    {
        $GLOBALS['__cap_user_meta'][$user_id][$meta_key] = $meta_value;

        return 1;
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];

        public function __construct(private string $method = '', private string $route = '') {}

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }
    }
}
