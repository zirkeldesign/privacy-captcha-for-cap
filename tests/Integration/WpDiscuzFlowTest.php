<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\WpDiscuz;
use ZirkelDesign\CapCaptcha\Settings;

/** wp_die() as an exception, so a test sees the end of an AJAX or comment request. */
final class CapWpDiscuzHalt extends RuntimeException {}

/*
 * wpDiscuz reads postId with filter_input(), which only sees a real HTTP
 * request, so its AJAX handler runs in PHP's built-in server against the same
 * fixture. A must-use plugin there fakes Cap's /siteverify from an option the
 * test sets.
 */
const CAP_WPDISCUZ_PORT = 8899;

function capWpDiscuzMuPlugin(): string
{
    return WPMU_PLUGIN_DIR.'/cap-wpdiscuz-flow.php';
}

beforeAll(function (): void {
    if (! defined('WPDISCUZ_DIR_PATH')) {
        return;
    }

    // A wp-cli activation skips the default form wpDiscuz creates on its first admin load. No-op once one exists.
    wpDiscuz()->wpdiscuzForm->createDefaultForm('1.0.0');

    wp_mkdir_p(WPMU_PLUGIN_DIR);
    file_put_contents(capWpDiscuzMuPlugin(), <<<'PHP'
        <?php
        add_filter('cap_captcha_protect_wpdiscuz', '__return_true');
        add_filter('cap_captcha_protect_comments', static fn ($on) => get_option('cap_flow_comments') ? true : $on);
        add_filter('cap_captcha_fail_open', static fn ($open) => get_option('cap_flow_fail_open') ? true : $open);
        add_filter('wp_is_comment_flood', '__return_false', 99);
        add_filter('pre_comment_approved', static fn () => 1, 99);
        add_filter('pre_http_request', static function ($preempt, $args, $url) {
            if (! str_contains((string) $url, '/siteverify')) {
                return $preempt;
            }
            $answer = get_option('cap_flow_cap', 'ok');
            if ($answer === 'down') {
                return new WP_Error('http_request_failed', 'Cap is unreachable');
            }
            update_option('cap_flow_verified', (int) get_option('cap_flow_verified', 0) + 1);

            return ['headers' => [], 'body' => wp_json_encode(['success' => $answer === 'ok']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        }, 10, 3);
        PHP);

    $GLOBALS['cap_wpdiscuz_server'] = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.CAP_WPDISCUZ_PORT, '-t', ABSPATH],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', CAP_WPDISCUZ_PORT);
        if ($socket) {
            fclose($socket);
            break;
        }
        usleep(50000);
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['cap_wpdiscuz_server'])) {
        proc_terminate($GLOBALS['cap_wpdiscuz_server']);
        proc_close($GLOBALS['cap_wpdiscuz_server']);
    }
    @unlink(capWpDiscuzMuPlugin());
    foreach (['cap_flow_cap', 'cap_flow_comments', 'cap_flow_fail_open', 'cap_flow_verified'] as $option) {
        delete_option($option);
    }
});

/** Settings reads the option once per request; drop what an earlier test loaded. */
function capWpDiscuzForgetSettings(): void
{
    (new ReflectionProperty(Settings::class, 'cache'))->setValue(Settings::get_instance(), null);
}

/**
 * Post a comment through wpDiscuz' real AJAX handler, as its script does.
 *
 * @param  array<string, string>  $fields
 * @return array{success: bool, data: mixed}
 */
function capWpDiscuzPost(int $postId, array $fields = [], string $cap = 'ok'): array
{
    update_option('cap_flow_cap', $cap);
    $curl = curl_init('http://127.0.0.1:'.CAP_WPDISCUZ_PORT.'/wp-admin/admin-ajax.php');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: localhost:'.CAP_WPDISCUZ_PORT],
        CURLOPT_POSTFIELDS => [
            'action' => 'wpdAddComment',
            'wpdiscuz_unique_id' => '0_0',
            'postId' => (string) $postId,
            'wc_comment' => 'A comment '.uniqid(),
            'wc_name' => 'Visitor',
            'wc_email' => 'visitor@example.test',
            'wpd_comment_depth' => '1',
            WpdiscuzHelper::GLOBAL_NONCE_NAME => wpDiscuz()->helper->generateNonce(),
            ...$fields,
        ],
    ]);
    $output = (string) curl_exec($curl);

    return json_decode($output, true) ?? ['success' => false, 'data' => $output];
}

function capWpDiscuzCommentCount(int $postId): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $postId));
}

beforeEach(function (): void {
    if (! defined('WPDISCUZ_DIR_PATH')) {
        $this->markTestSkipped('wpDiscuz is not active in the WordPress fixture.');
    }

    $this->postId = wp_insert_post([
        'post_title' => 'wpDiscuz flow',
        'post_status' => 'publish',
        'post_type' => 'post',
        'comment_status' => 'open',
    ]);

    update_option('cap_flow_comments', 0);
    update_option('cap_flow_fail_open', 0);
    delete_option('cap_flow_verified');
    $this->filters = [
        ['cap_captcha_protect_wpdiscuz', '__return_true', 10, 1],
        ['wp_die_handler', static fn (): Closure => static function ($message): never {
            throw new CapWpDiscuzHalt(is_string($message) ? $message : '');
        }, 10, 1],
    ];
    foreach ($this->filters as [$hook, $callback, $priority, $args]) {
        add_filter($hook, $callback, $priority, $args);
    }
    // wpDiscuz' form rendering reads the global post, wp_new_comment() the visitor's address.
    $GLOBALS['post'] = get_post($this->postId);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    capWpDiscuzForgetSettings();
});

afterEach(function (): void {
    if (empty($this->postId)) {
        return;
    }

    foreach ($this->filters as [$hook, $callback, $priority]) {
        remove_filter($hook, $callback, $priority);
    }
    wp_delete_post($this->postId, true);
    unset($GLOBALS['post'], $_SERVER['REMOTE_ADDR']);
    capWpDiscuzForgetSettings();
});

it('renders a widget per form id inside the real main form and the reply template', function (): void {
    $form = wpDiscuz()->wpdiscuzForm->getForm($this->postId);
    $form->initFormFields();
    $user = WpdiscuzHelper::getCurrentUser();

    ob_start();
    $form->renderFrontForm(1, '0_0', 0, $user, $this->postId);
    $form->renderFrontForm(0, 'wpdiscuzuniqueid', 0, $user, $this->postId);
    $html = (string) ob_get_clean();

    expect($html)->toMatch('/<cap-widget id="cap-captcha-wpdiscuz-0_0"[^>]*>.*?<input id="wpd-field-submit-0_0"/s')
        ->and($html)->toMatch('/<cap-widget id="cap-captcha-wpdiscuz-wpdiscuzuniqueid"[^>]*>.*?<input id="wpd-field-submit-wpdiscuzuniqueid"/s')
        ->and(wp_script_is('cap-captcha-wpdiscuz', 'enqueued'))->toBeTrue()
        ->and(apply_filters('wpdiscuz_js_options', [], null))->toHaveKey(WpDiscuz::PHRASE_KEY);
});

it('turns the real wpDiscuz submit button into the floating trigger', function (): void {
    $floating = static fn ($settings): array => ['display_mode' => Settings::MODE_FLOATING] + (array) $settings;
    add_filter('option_'.Settings::OPTION_KEY, $floating);
    capWpDiscuzForgetSettings();
    $form = wpDiscuz()->wpdiscuzForm->getForm($this->postId);
    $form->initFormFields();

    ob_start();
    try {
        $form->renderFrontForm(0, 'wpdiscuzuniqueid', 0, WpdiscuzHelper::getCurrentUser(), $this->postId);
    } finally {
        $html = (string) ob_get_clean();
        remove_filter('option_'.Settings::OPTION_KEY, $floating);
    }

    expect($html)->toContain('<cap-widget id="cap-captcha-wpdiscuz-wpdiscuzuniqueid"')
        ->and(substr_count($html, 'data-cap-floating='))->toBe(1)
        ->and($html)->toContain('<input data-cap-floating="#cap-captcha-wpdiscuz-wpdiscuzuniqueid" data-cap-floating-position="top" id="wpd-field-submit-wpdiscuzuniqueid"')
        ->and($html)->toContain('</form>');
});

it('rejects a real wpDiscuz comment without a token', function (): void {
    $response = capWpDiscuzPost($this->postId);

    expect($response)->toBe(['success' => false, 'data' => WpDiscuz::PHRASE_KEY])
        ->and(capWpDiscuzCommentCount($this->postId))->toBe(0);
});

it('rejects a token the Cap server refuses', function (): void {
    $response = capWpDiscuzPost($this->postId, ['cap-token' => 'rejected-token'], 'reject');

    expect($response['data'])->toBe(WpDiscuz::PHRASE_KEY)
        ->and(capWpDiscuzCommentCount($this->postId))->toBe(0);
});

it('accepts a verified token with one verification, also when the Comments integration is on', function (): void {
    update_option('cap_flow_comments', 1);
    $response = capWpDiscuzPost($this->postId, ['cap-token' => 'solved-token']);

    expect($response['success'])->toBeTrue()
        ->and(capWpDiscuzCommentCount($this->postId))->toBe(1)
        // The server wrote the counter; read past this process' option cache.
        ->and((int) $GLOBALS['wpdb']->get_var("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'cap_flow_verified'"))->toBe(1);
});

it('lets a comment through and marks it when Cap is unreachable and fail-open is on', function (): void {
    update_option('cap_flow_fail_open', 1);
    $response = capWpDiscuzPost($this->postId, ['cap-token' => 'token'], 'down');

    expect($response['success'])->toBeTrue()
        ->and(get_comment_meta((int) $response['data']['new_comment_id'], 'cap_captcha_fail_open', true))->toBe('1');
});

it('keeps a comment out when Cap is unreachable and fail-open is off', function (): void {
    $response = capWpDiscuzPost($this->postId, ['cap-token' => 'token'], 'down');

    expect($response['data'])->toBe(WpDiscuz::PHRASE_KEY)
        ->and(capWpDiscuzCommentCount($this->postId))->toBe(0);
});

it('blocks a direct post to wp-comments-post.php on a wpDiscuz post', function (): void {
    $_POST = ['comment_post_ID' => (string) $this->postId];
    try {
        wp_handle_comment_submission([
            'comment_post_ID' => $this->postId,
            'comment' => 'Direct bot post',
            'author' => 'Bot',
            'email' => 'bot@example.test',
        ]);
        $this->fail('A direct post without a token was accepted.');
    } catch (CapWpDiscuzHalt $halt) {
        expect($halt->getMessage())->toContain('CAPTCHA verification failed');
    } finally {
        $_POST = [];
    }

    expect(capWpDiscuzCommentCount($this->postId))->toBe(0);
});
