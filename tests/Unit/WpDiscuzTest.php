<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\WpDiscuz;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

if (! function_exists('wpDiscuz')) {
    /** wpDiscuz' accessor, answering whether a post uses a wpDiscuz form. */
    function wpDiscuz(): object
    {
        $form = new class
        {
            public function getFormID(): int
            {
                return (int) ($GLOBALS['__cap_wpdiscuz_form_id'] ?? 0);
            }
        };

        return (object) ['wpdiscuzForm' => new class($form)
        {
            public function __construct(private object $form) {}

            public function getForm(int $postId): object
            {
                return $this->form;
            }
        }];
    }
}

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    cap_reset_remote_stub();
    $_POST = [];
    $GLOBALS['__cap_modules'] = [];
    $GLOBALS['__cap_scripts'] = [];
    $GLOBALS['__cap_comment_meta'] = [];
    $GLOBALS['__cap_wpdiscuz_form_id'] = 1;
});

/** @param array<string, bool> $integrations */
function capWpDiscuz(
    bool $enabled = true,
    bool $failOpen = false,
    bool $configured = true,
    string $mode = Settings::MODE_INLINE,
    array $integrations = [],
): WpDiscuz {
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => $configured ? 'https://cap.example.com/' : '',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'display_mode' => $mode,
        'fail_open' => $failOpen,
        'integrations' => ['wpdiscuz' => $enabled, ...$integrations],
    ]);
    $settings = new Settings;

    return new WpDiscuz($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

/** Renders the widget the way wpDiscuz' submit field does, followed by its button and the form's end. */
function capWpDiscuzForm(WpDiscuz $integration, string $uniqueId = '0_0'): string
{
    ob_start();
    $integration->renderWidget(null, $uniqueId, $uniqueId === '0_0');
    echo '<div class="wc-field-submit"><input id="wpd-field-submit-'.$uniqueId.'" class="wc_comm_submit wpd_not_clicked wpd-prim-button" type="submit" name="submit" value="Post Comment"/></div>';
    $integration->wireSubmitButton();

    return (string) ob_get_clean();
}

function capWpDiscuzHalt(callable $call): ?CapTestHalt
{
    try {
        $call();
    } catch (CapTestHalt $halt) {
        return $halt;
    }

    return null;
}

it('identifies itself and is unavailable without wpDiscuz', function (): void {
    $integration = capWpDiscuz();

    expect($integration->id())->toBe('wpdiscuz')
        ->and(Settings::SURFACES)->toContain('wpdiscuz')
        ->and($integration->isAvailable())->toBeFalse();
});

it('registers the wpDiscuz render, phrase and verification hooks', function (): void {
    capWpDiscuz()->register();

    expect($GLOBALS['__cap_filters'])->toHaveKeys([
        'wpdiscuz_submit_button_before',
        'comment_form',
        'wpdiscuz_js_options',
        'wpdiscuz_before_wp_new_comment',
        'preprocess_comment',
        'comment_post',
    ]);
});

it('renders an inline widget whose id follows the form unique id', function (): void {
    $integration = capWpDiscuz();
    $main = capWpDiscuzForm($integration);
    $template = capWpDiscuzForm($integration, 'wpdiscuzuniqueid');

    expect($main)->toContain('<div class="cap-captcha--wpdiscuz">')
        ->and($main)->toContain('<cap-widget id="cap-captcha-wpdiscuz-0_0"')
        ->and($main)->not->toContain('data-cap-floating')
        // wpDiscuz swaps this placeholder for the real id in every cloned reply form.
        ->and($template)->toContain('<cap-widget id="cap-captcha-wpdiscuz-wpdiscuzuniqueid"')
        ->and($GLOBALS['__cap_modules'])->toBe([Enqueuer::WIDGET_MODULE_ID])
        ->and($GLOBALS['__cap_scripts']['cap-captcha-wpdiscuz']['deps'])->toBe(['jquery']);
});

it('makes the wpDiscuz submit button the floating trigger', function (): void {
    $integration = capWpDiscuz(mode: Settings::MODE_FLOATING);
    $html = capWpDiscuzForm($integration, 'wpdiscuzuniqueid');

    expect($html)->toContain('<cap-widget id="cap-captcha-wpdiscuz-wpdiscuzuniqueid" data-cap-api-endpoint="https://cap.example.com/sitekey/" hidden')
        ->and($html)->toContain('<input data-cap-floating="#cap-captcha-wpdiscuz-wpdiscuzuniqueid" data-cap-floating-position="top" id="wpd-field-submit-wpdiscuzuniqueid"')
        ->and($html)->not->toContain('<button')
        ->and($GLOBALS['__cap_modules'])->toContain(Enqueuer::FLOATING_MODULE_ID);

    // The buffer is closed: a later core comment_form does not swallow output.
    ob_start();
    $integration->wireSubmitButton();
    echo 'after';
    expect((string) ob_get_clean())->toBe('after');
});

it('renders a token field per form in programmatic mode and leaves the shared solver out', function (): void {
    $html = capWpDiscuzForm(capWpDiscuz(mode: Settings::MODE_PROGRAMMATIC));

    expect($html)->toContain('<div class="cap-captcha--wpdiscuz"><input type="hidden" name="cap-token"')
        ->and($html)->toContain('class="cap-captcha-wpdiscuz-error" role="alert" hidden')
        ->and($html)->not->toContain('<cap-widget')
        ->and($GLOBALS['__cap_modules'])->toBe([Enqueuer::WIDGET_MODULE_ID])
        ->and($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-wpdiscuz');
});

it('renders nothing when the surface is off or the plugin is not configured', function (): void {
    $button = '<input id="wpd-field-submit-0_0" class="wc_comm_submit wpd_not_clicked wpd-prim-button" type="submit" name="submit" value="Post Comment"/>';

    expect(capWpDiscuzForm(capWpDiscuz(false)))->toBe('<div class="wc-field-submit">'.$button.'</div>')
        ->and(capWpDiscuzForm(capWpDiscuz(true, false, false)))->toBe('<div class="wc-field-submit">'.$button.'</div>')
        ->and($GLOBALS['__cap_scripts'])->toBe([]);
});

it('adds a translated phrase for its error key', function (): void {
    expect(capWpDiscuz()->addPhrase(['wc_msg_required_fields' => 'Required']))->toBe([
        'wc_msg_required_fields' => 'Required',
        WpDiscuz::PHRASE_KEY => 'CAPTCHA verification failed. Please try again.',
    ]);
});

it('lets a comment with a valid token through', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $_POST['cap-token'] = 'valid';

    expect(capWpDiscuzHalt(fn () => capWpDiscuz()->verifyComment()))->toBeNull();
    $request = json_decode((string) $GLOBALS['__cap_remote_last_request']['args']['body'], true);
    expect($request['response'])->toBe('valid');
});

it('answers an invalid or missing token with its wpDiscuz error key', function (?string $token): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    if ($token !== null) {
        $_POST['cap-token'] = $token;
    }

    $halt = capWpDiscuzHalt(fn () => capWpDiscuz(true, true)->verifyComment());

    expect($halt)->not->toBeNull()
        ->and($halt->getMessage())->toBe('wp_send_json_error')
        ->and($halt->data)->toBe(WpDiscuz::PHRASE_KEY);
})->with(['invalid' => ['invalid'], 'missing' => [null]]);

it('leaves comments untouched when the surface is off', function (): void {
    expect(capWpDiscuzHalt(fn () => capWpDiscuz(false)->verifyComment()))->toBeNull()
        ->and($GLOBALS['__cap_remote_last_request'] ?? null)->toBeNull();
});

it('lets a comment through when Cap is unreachable and fail-open is on, and marks it', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('unreachable', 'unreachable');
    $_POST['cap-token'] = 'token';
    $GLOBALS['__cap_actions'] = [];

    $integration = capWpDiscuz(true, true);
    $integration->verifyComment();
    $integration->annotateFailOpen(42);
    $integration->annotateFailOpen(43);

    expect($GLOBALS['__cap_comment_meta'])->toBe([42 => ['cap_captcha_fail_open' => 1]])
        ->and($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'])->toBe([['wpdiscuz', ['comment_id' => 42]]])
        ->and(capWpDiscuzHalt(fn () => capWpDiscuz(true, false)->verifyComment()))->not->toBeNull();
});

it('guards direct posts to wp-comments-post.php on posts that use a wpDiscuz form', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST = ['comment_post_ID' => '7', 'cap-token' => 'invalid'];
    $comment = ['comment_post_ID' => 7, 'comment_type' => 'comment'];

    $halt = capWpDiscuzHalt(fn () => capWpDiscuz()->verifyDirectPost($comment));

    expect($halt?->data)->toMatchArray(['response' => 403]);
});

it('leaves direct posts to the core comment form, pingbacks and other posts alone', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $_POST = ['comment_post_ID' => '7', 'cap-token' => 'invalid'];
    $comment = ['comment_post_ID' => 7, 'comment_type' => 'comment'];
    $wpdiscuzAndComments = capWpDiscuz(integrations: ['comments' => true]);
    $pingback = ['comment_post_ID' => 7, 'comment_type' => 'pingback'];

    expect($wpdiscuzAndComments->verifyDirectPost($comment))->toBe($comment)
        ->and(capWpDiscuz()->verifyDirectPost($pingback))->toBe($pingback)
        ->and(capWpDiscuz(false)->verifyDirectPost($comment))->toBe($comment);

    $GLOBALS['__cap_wpdiscuz_form_id'] = 0;
    expect(capWpDiscuz()->verifyDirectPost($comment))->toBe($comment);

    // wpDiscuz' own AJAX post carries postId, not comment_post_ID; verifyComment covers it.
    $GLOBALS['__cap_wpdiscuz_form_id'] = 1;
    $_POST = ['postId' => '7', 'cap-token' => 'invalid'];
    expect(capWpDiscuz()->verifyDirectPost($comment))->toBe($comment);
});
