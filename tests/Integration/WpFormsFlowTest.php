<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Integration\WpForms;
use ZirkelDesign\CapCaptcha\Settings;

/*
 * Non-AJAX submissions run through WPForms' own process() in this process.
 * WPForms accepts an AJAX submission only from a real admin-ajax request
 * (wp_doing_ajax()) and ends it with wp_send_json(), so those go through PHP's
 * built-in server against the same fixture. A must-use plugin there fakes
 * Cap's /siteverify from an option the test sets.
 */
const CAP_WPFORMS_PORT = 8897;

function capWpFormsMuPlugin(): string
{
    return WPMU_PLUGIN_DIR.'/cap-wpforms-flow.php';
}

beforeAll(function (): void {
    if (! defined('WPFORMS_VERSION')) {
        return;
    }

    wp_mkdir_p(WPMU_PLUGIN_DIR);
    file_put_contents(capWpFormsMuPlugin(), <<<'PHP'
        <?php
        add_filter('cap_captcha_fail_open', static fn ($open) => get_option('cap_wpf_fail_open') ? true : $open);
        add_filter('pre_wp_mail', '__return_true');
        // A handler after the CAPTCHA check that refuses the submission, as a declined payment would.
        add_action('wpforms_process', static function ($fields, $entry, $formData) {
            if (get_option('cap_wpf_fail_later')) {
                wpforms()->obj('process')->errors[$formData['id']]['footer'] = 'Payment declined.';
            }
        }, 20, 3);
        add_filter('pre_http_request', static function ($preempt, $args, $url) {
            if (! str_contains((string) $url, '/siteverify')) {
                return $preempt;
            }
            update_option('cap_wpf_verified', (int) get_option('cap_wpf_verified', 0) + 1);
            $answer = get_option('cap_wpf_cap', 'ok');
            if ($answer === 'down') {
                return new WP_Error('http_request_failed', 'Cap is unreachable');
            }

            return ['headers' => [], 'body' => wp_json_encode(['success' => $answer === 'ok']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
        }, 10, 3);
        PHP);

    $GLOBALS['cap_wpforms_server'] = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:'.CAP_WPFORMS_PORT, '-t', ABSPATH],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', CAP_WPFORMS_PORT);
        if ($socket) {
            fclose($socket);
            break;
        }
        usleep(50000);
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['cap_wpforms_server'])) {
        proc_terminate($GLOBALS['cap_wpforms_server']);
        proc_close($GLOBALS['cap_wpforms_server']);
    }
    @unlink(capWpFormsMuPlugin());
    foreach (['cap_wpf_cap', 'cap_wpf_fail_open', 'cap_wpf_fail_later', 'cap_wpf_verified'] as $option) {
        delete_option($option);
    }
});

/** Settings reads the option once per request; drop what an earlier test loaded. */
function capWpFormsForgetSettings(): void
{
    (new ReflectionProperty(Settings::class, 'cache'))->setValue(Settings::get_instance(), null);
}

/** Runs $callback with some plugin settings changed for this process. */
function capWpFormsWithSettings(array $changes, callable $callback): mixed
{
    $filter = static fn ($settings): array => $changes + (array) $settings;
    add_filter('option_'.Settings::OPTION_KEY, $filter);
    capWpFormsForgetSettings();
    try {
        return $callback();
    } finally {
        remove_filter('option_'.Settings::OPTION_KEY, $filter);
        capWpFormsForgetSettings();
    }
}

/** A published form with one required name field, the way the builder saves it. */
function capWpFormsCreate(string $protection = 'always', bool $ajax = false): int
{
    $formId = wp_insert_post(['post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'Cap flow']);
    $data = [
        'id' => (string) $formId,
        'field_id' => 2,
        'fields' => [
            1 => ['id' => '1', 'type' => 'name', 'label' => 'Name', 'format' => 'simple', 'required' => '1', 'size' => 'medium'],
        ],
        'settings' => [
            'form_title' => 'Cap flow',
            'submit_text' => 'Submit',
            'ajax_submit' => $ajax ? '1' : '0',
            'notification_enable' => '0',
            'confirmations' => [1 => ['type' => 'message', 'message' => 'Thanks!']],
            WpForms::MODE_KEY => $protection,
        ],
    ];
    wp_update_post(['ID' => $formId, 'post_content' => wpforms_encode($data)]);

    return $formId;
}

/**
 * Submits through WPForms' own process(), as its listener on `wp` does for a
 * form without AJAX.
 *
 * @return array<string, mixed> WPForms' errors for the form.
 */
function capWpFormsSubmit(int $formId, string $name = 'Ada Lovelace', ?string $token = 'solved-token'): array
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['wpforms' => ['id' => (string) $formId, 'fields' => [1 => $name]]];
    if ($token !== null) {
        $_POST['cap-token'] = $token;
    }

    $process = wpforms()->obj('process');
    $process->process($_POST['wpforms']);

    return $process->errors[$formId] ?? [];
}

/**
 * Posts an AJAX form through admin-ajax.php, as WPForms' script does.
 *
 * @return array{success: bool, data: mixed}
 */
function capWpFormsAjax(int $formId, string $name, string $cap = 'ok', string $token = 'solved-token'): array
{
    update_option('cap_wpf_cap', $cap);
    $curl = curl_init('http://127.0.0.1:'.CAP_WPFORMS_PORT.'/wp-admin/admin-ajax.php');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Host: localhost:8899'],
        CURLOPT_POSTFIELDS => [
            'action' => 'wpforms_submit',
            'wpforms[id]' => (string) $formId,
            'wpforms[fields][1]' => $name,
            'cap-token' => $token,
        ],
    ]);
    $output = (string) curl_exec($curl);

    return json_decode($output, true) ?? ['success' => false, 'data' => $output];
}

/** Answers Cap's /siteverify in this process: 'ok', 'bad' or 'down'. */
function capWpFormsCap(string $answer): Closure
{
    $stub = static function ($preempt, $args, $url) use ($answer) {
        if ($preempt !== false || ! str_contains((string) $url, '/siteverify')) {
            return $preempt;
        }
        $GLOBALS['cap_wpforms_verified'] = ($GLOBALS['cap_wpforms_verified'] ?? 0) + 1;
        if ($answer === 'down') {
            return new WP_Error('http_request_failed', 'Cap is unreachable');
        }

        return ['headers' => [], 'body' => wp_json_encode(['success' => $answer === 'ok']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    };
    add_filter('pre_http_request', $stub, 10, 3);

    return $stub;
}

beforeEach(function (): void {
    if (! defined('WPFORMS_VERSION')) {
        $this->markTestSkipped('WPForms is not active in the WordPress fixture.');
    }

    $GLOBALS['cap_wpforms_verified'] = 0;
    $this->capStub = capWpFormsCap('ok');
    $this->formIds = [];
    add_filter('pre_wp_mail', '__return_true');
});

afterEach(function (): void {
    if (! defined('WPFORMS_VERSION')) {
        return;
    }

    // The fixture is shared with the other suites: a stub left behind would answer their /siteverify calls too.
    remove_filter('pre_http_request', $this->capStub, 10);
    remove_filter('pre_wp_mail', '__return_true');
    foreach ($this->formIds as $formId) {
        wp_delete_post($formId, true);
    }
    update_option('cap_wpf_fail_later', 0);
    update_option('cap_wpf_fail_open', 0);
    $_POST = [];
});

it('renders the widget above the submit button of a real WPForms form', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate();

    $html = do_shortcode('[wpforms id="'.$formId.'"]');

    expect($html)->toMatch('#<div class="cap-captcha--wpforms"><div class="cap-captcha cap-captcha--inline"><cap-widget id="cap-captcha-wpforms-\d+"#')
        ->and(strpos($html, 'cap-captcha--wpforms'))->toBeLessThan(strpos($html, 'name="wpforms[submit]"'))
        ->and(wp_script_is('cap-captcha-wpforms', 'enqueued'))->toBeTrue();
});

it('renders a widget of its own for the same form twice and for a second form', function (): void {
    $this->formIds[] = $first = capWpFormsCreate();
    $this->formIds[] = $second = capWpFormsCreate();

    $html = do_shortcode('[wpforms id="'.$first.'"][wpforms id="'.$first.'"][wpforms id="'.$second.'"]');

    preg_match_all('#<cap-widget id="([^"]+)"#', $html, $ids);
    expect($ids[1])->toHaveCount(3)
        ->and(array_unique($ids[1]))->toHaveCount(3);
});

it('turns the real submit button into the floating trigger', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate();

    $html = capWpFormsWithSettings(['display_mode' => Settings::MODE_FLOATING], static fn () => do_shortcode('[wpforms id="'.$formId.'"]'));

    expect(preg_match('/<cap-widget id="([^"]+)"/', $html, $widget))->toBe(1)
        ->and(substr_count($html, 'data-cap-floating='))->toBe(1)
        ->and($html)->toMatch('/<button data-cap-floating="#'.preg_quote($widget[1], '/').'"[^>]*type="submit" name="wpforms\[submit\]"/')
        ->and($html)->not->toContain('cap-captcha-floating-trigger');
});

it('follows the per-form setting over the global protect-all', function (string $protection, bool $protectAll, bool $rendered): void {
    $this->formIds[] = $formId = capWpFormsCreate($protection);

    $html = capWpFormsWithSettings(['wpforms_protect_all' => $protectAll], static fn () => do_shortcode('[wpforms id="'.$formId.'"]'));

    expect(str_contains($html, '<cap-widget'))->toBe($rendered);
})->with([
    'default with protect-all' => ['default', true, true],
    'default without protect-all' => ['default', false, false],
    'always without protect-all' => ['always', false, true],
    'never with protect-all' => ['never', true, false],
]);

it('rejects a real submission without a token', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate();

    $errors = capWpFormsSubmit($formId, token: null);

    expect($errors['footer'] ?? null)->toBe('CAPTCHA verification failed. Please try again.')
        ->and($GLOBALS['cap_wpforms_verified'])->toBe(0);
});

it('rejects a token Cap refuses', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate();
    remove_filter('pre_http_request', $this->capStub, 10);
    $this->capStub = capWpFormsCap('bad');

    expect(capWpFormsSubmit($formId)['footer'] ?? null)->toBe('CAPTCHA verification failed. Please try again.');
});

it('accepts a verified token', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate();
    $completed = 0;
    $count = static function () use (&$completed): void {
        $completed++;
    };
    add_action('wpforms_process_complete', $count);

    $errors = capWpFormsSubmit($formId);
    remove_action('wpforms_process_complete', $count);

    expect($errors)->toBe([])
        ->and($completed)->toBe(1)
        ->and($GLOBALS['cap_wpforms_verified'])->toBe(1);
});

it('does not ask Cap about a submission WPForms refused for a missing field', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate();

    $errors = capWpFormsSubmit($formId, name: '');

    expect($errors)->toHaveKey(1)
        ->and($errors)->not->toHaveKey('footer')
        ->and($GLOBALS['cap_wpforms_verified'])->toBe(0);
});

it('lets a form set to Never through without a token', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate('never');

    expect(capWpFormsSubmit($formId, token: null))->toBe([]);
});

it('passes on an unreachable Cap only with fail-open, and fires the fail-open action', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate();
    remove_filter('pre_http_request', $this->capStub, 10);
    $this->capStub = capWpFormsCap('down');
    $passes = [];
    $record = static function (string $context, array $data) use (&$passes): void {
        $passes[] = [$context, $data];
    };
    add_action('cap_captcha_fail_open_pass', $record, 10, 2);

    $closed = capWpFormsSubmit($formId);
    $open = capWpFormsWithSettings(['fail_open' => true], static fn () => capWpFormsSubmit($formId));
    remove_action('cap_captcha_fail_open_pass', $record, 10);

    expect($closed['footer'] ?? null)->toBe('CAPTCHA verification failed. Please try again.')
        ->and($open)->toBe([])
        ->and($passes)->toBe([['wpforms', ['form_id' => $formId, 'entry_id' => 0]]]);
});

it('keeps the token of an AJAX submission WPForms refused before the check', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate(ajax: true);
    update_option('cap_wpf_verified', 0);

    $response = capWpFormsAjax($formId, '');

    expect($response['success'])->toBeFalse()
        ->and($response['data'][WpForms::KEPT_KEY] ?? null)->toBeTrue()
        ->and((int) get_option('cap_wpf_verified'))->toBe(0);
});

it('asks for a new token once the AJAX check failed or a later handler refused the submission', function (string $cap, bool $failLater, string $error): void {
    $this->formIds[] = $formId = capWpFormsCreate(ajax: true);
    update_option('cap_wpf_fail_later', $failLater ? 1 : 0);

    $response = capWpFormsAjax($formId, 'Ada Lovelace', $cap);

    expect($response['success'])->toBeFalse()
        ->and($response['data'])->not->toHaveKey(WpForms::KEPT_KEY)
        ->and(implode(' ', (array) ($response['data']['errors']['general'] ?? [])))->toContain($error);
})->with([
    'rejected' => ['bad', false, 'CAPTCHA verification failed. Please try again.'],
    'refused after the check' => ['ok', true, 'Payment declined.'],
]);

it('accepts an AJAX submission with a verified token', function (): void {
    $this->formIds[] = $formId = capWpFormsCreate(ajax: true);

    $response = capWpFormsAjax($formId, 'Ada Lovelace');

    expect($response['success'])->toBeTrue()
        ->and($response['data']['confirmation'] ?? '')->toContain('Thanks!');
});

it('adds the protection select to the builder\'s spam protection panel', function (): void {
    if (! function_exists('wpforms_panel_field')) {
        require_once WPFORMS_PLUGIN_DIR.'includes/admin/builder/functions.php';
    }

    ob_start();
    do_action('wpforms_admin_builder_anti_spam_panel_content', ['id' => 1, 'settings' => [WpForms::MODE_KEY => 'never']]);
    $html = (string) ob_get_clean();

    expect($html)->toContain('name="settings[cap_captcha_mode]"')
        ->and($html)->toMatch('#<option value="never"\s+selected#')
        ->and($html)->toContain('value="always"');
});
