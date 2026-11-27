<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Integration\ElementorAtomicForm;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    cap_reset_remote_stub();
    $_POST = [];
    $GLOBALS['__cap_modules'] = [];
    $GLOBALS['__cap_scripts'] = [];
    $GLOBALS['__cap_inline_scripts'] = [];
    $GLOBALS['__cap_actions'] = [];
});

function capAtomic(bool $enabled = true, bool $configured = true, bool $failOpen = false): ElementorAtomicForm
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => $configured ? 'secret' : '',
        'fail_open' => $failOpen,
        'integrations' => ['elementor_atomic' => $enabled],
    ]);
    $settings = new Settings;

    return new ElementorAtomicForm($settings, new Enqueuer($settings), new TokenVerifier($settings));
}

/** The answer verify() ends the request with, or null when it lets Elementor's handler run. */
function capAtomicVerdict(ElementorAtomicForm $integration): ?string
{
    try {
        $integration->verify();
    } catch (CapTestHalt $halt) {
        return $halt->getMessage().': '.($halt->data['message'] ?? '');
    }

    return null;
}

it('sits in front of Elementor on both ajax actions', function (): void {
    capAtomic()->register();

    foreach (['wp_ajax_elementor_pro_atomic_forms_send_form', 'wp_ajax_nopriv_elementor_pro_atomic_forms_send_form'] as $hook) {
        expect($GLOBALS['__cap_filters'][$hook][0]['priority'] ?? null)->toBe(1);
    }

    // Without the render hook no script loads, and every form would be refused.
    expect($GLOBALS['__cap_filters'])->toHaveKey('elementor/frontend/e-form/before_render');
});

// The smallest request Elementor accepts names a post, a form and one field. None of them is read here.
it('refuses a submission without a token', function (): void {
    $_POST = ['action' => 'elementor_pro_atomic_forms_send_form', 'post_id' => '5', 'form_id' => 'abc', 'form_fields' => [['id' => 'x', 'value' => 'hello']]];

    expect(capAtomicVerdict(capAtomic()))->toBe('wp_send_json_error: CAPTCHA verification failed. Please try again.');
});

it('refuses an empty request', function (): void {
    expect(capAtomicVerdict(capAtomic()))->toStartWith('wp_send_json_error: ');
});

it('refuses a token the Cap server rejects', function (): void {
    $_POST = ['cap-token' => 'spent'];
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];

    expect(capAtomicVerdict(capAtomic()))->toStartWith('wp_send_json_error: ');
});

it('lets a verified submission through to Elementor', function (): void {
    $_POST = ['cap-token' => 'good'];
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    expect(capAtomicVerdict(capAtomic()))->toBeNull()
        ->and($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'] ?? [])->toBe([]);
});

it('checks nothing while the switch is off or the plugin is not configured', function (): void {
    expect(capAtomicVerdict(capAtomic(enabled: false)))->toBeNull()
        ->and(capAtomicVerdict(capAtomic(configured: false)))->toBeNull();
});

it('reports a submission that passed only because the Cap server was unreachable', function (): void {
    $_POST = ['cap-token' => 'any'];
    $GLOBALS['__cap_remote_response'] = new WP_Error('unreachable', 'unreachable');

    expect(capAtomicVerdict(capAtomic(failOpen: true)))->toBeNull()
        ->and($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'][0] ?? null)->toBe(['elementor_atomic', []])
        ->and(capAtomicVerdict(capAtomic(failOpen: false)))->toStartWith('wp_send_json_error: ');
});

it('loads the solver and the script where an atomic form renders', function (): void {
    capAtomic()->enqueue();

    expect($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-elementor-atomic')
        ->and($GLOBALS['__cap_inline_scripts']['cap-captcha-elementor-atomic'])->toContain('CAP_CAPTCHA_ATOMIC_FORM')
        ->and($GLOBALS['__cap_modules'])->not->toBeEmpty();
});

it('loads nothing while the switch is off, and nothing for a site key that wants a press on the widget', function (): void {
    capAtomic(enabled: false)->enqueue();
    expect($GLOBALS['__cap_scripts'])->toBe([]);

    add_filter('cap_captcha_force_inline', fn (): bool => true);
    $integration = capAtomic();
    $integration->enqueue();

    // No background solve is possible with such a key: the form stays closed instead of unprotected.
    expect($GLOBALS['__cap_scripts'])->toBe([])
        ->and(capAtomicVerdict($integration))->toStartWith('wp_send_json_error: ');
});

it('prints its configuration once on a page with several atomic forms', function (): void {
    $calls = 0;
    add_filter('cap_captcha_force_inline', function (bool $forced) use (&$calls): bool {
        $calls++;

        return $forced;
    });
    $integration = capAtomic();
    $integration->enqueue();
    $afterFirst = $calls;
    $integration->enqueue();

    // The second form returns before anything is looked up or printed again.
    expect($afterFirst)->toBeGreaterThan(0)
        ->and($calls)->toBe($afterFirst);
});
