<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\FluentForms;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    cap_reset_remote_stub();
    $_POST = [];
    $GLOBALS['__cap_modules'] = [];
    $GLOBALS['__cap_scripts'] = [];
});

function capFluentForms(
    bool $enabled = true,
    bool $failOpen = false,
    bool $configured = true,
    string $mode = Settings::MODE_INLINE,
): FluentForms {
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => $configured ? 'https://cap.example.com/' : '',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'display_mode' => $mode,
        'fail_open' => $failOpen,
        'integrations' => ['fluent_forms' => $enabled],
    ]);
    $settings = new Settings;

    return new FluentForms($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

/**
 * The form row as Fluent Forms hands it to the validation filter.
 *
 * @param  array<int, array<string, mixed>>|null  $fields  Defaults to a form holding just the CAPTCHA field.
 */
function capFluentForm(?array $fields = null): object
{
    $fields ??= [['element' => FluentForms::ELEMENT, 'attributes' => ['name' => 'cap-token']]];

    return (object) ['id' => 1, 'form_fields' => json_encode(['fields' => $fields])];
}

/** @return array<string, array<string, mixed>> */
function capFluentFields(): array
{
    return ['cap-token' => ['element' => FluentForms::ELEMENT, 'raw' => ['attributes' => ['name' => 'cap-token']]]];
}

/** @param array<string, mixed>|null $fields The decoded form fields, as Fluent Forms sets them while rendering. */
function capFluentRender(FluentForms $integration, ?array $fields = null): string
{
    ob_start();
    $integration->render([], (object) ['id' => 1, 'fields' => $fields]);

    return (string) ob_get_clean();
}

it('identifies itself and is unavailable without Fluent Forms', function (): void {
    $integration = capFluentForms();

    expect($integration->id())->toBe('fluent_forms')
        ->and(Settings::SURFACES)->toContain('fluent_forms')
        ->and(defined('FLUENTFORM'))->toBeFalse()
        ->and($integration->isAvailable())->toBeFalse();
});

it('registers the Fluent Forms editor, renderer and submission hooks', function (): void {
    capFluentForms()->register();

    expect($GLOBALS['__cap_filters'])->toHaveKeys([
        'fluentform/editor_components',
        'fluentform/editor_element_settings_placement',
        'fluentform/form_input_types',
        'fluentform/render_item_cap_captcha',
        'fluentform/render_item_submit_button',
        'fluentform/validation_errors',
        'fluentform/insert_response_data',
    ]);
});

it('adds the field to the editor without touching existing components', function (): void {
    $integration = capFluentForms();

    $components = $integration->addComponent(['advanced' => [['element' => 'existing']], 'general' => []]);
    $component = $components['advanced'][1];

    expect($components['advanced'][0])->toBe(['element' => 'existing'])
        ->and($component['element'])->toBe(FluentForms::ELEMENT)
        ->and($component['attributes']['name'])->toBe('cap-token')
        ->and($component['editor_options']['template'])->toBe('customHTML')
        ->and($integration->addInputType(['input_text']))->toBe(['input_text', FluentForms::ELEMENT])
        ->and($integration->addEditorSettings(['input_text' => ['general' => ['label']]]))->toBe([
            'input_text' => ['general' => ['label']],
            FluentForms::ELEMENT => ['general' => [], 'advanced' => []],
        ]);
});

it('requires a token only when the enabled, configured form holds the field', function (): void {
    expect(capFluentForms()->validate([], [], capFluentForm([])))->toBe([])
        ->and(capFluentForms()->validate([], [], capFluentForm([['element' => 'input_text']])))->toBe([])
        ->and(capFluentForms()->validate([], [], capFluentForm()))->toHaveKey('cap-token')
        ->and(capFluentForms(false)->validate([], [], capFluentForm()))->toBe([])
        ->and(capFluentForms(true, false, false)->validate([], [], capFluentForm()))->toBe([]);
});

it('keeps errors other validators already reported', function (): void {
    $errors = capFluentForms()->validate(['email' => ['Required']], [], capFluentForm());

    expect($errors)->toHaveKeys(['email', 'cap-token']);
});

it('finds the field inside containers and steps', function (): void {
    $nested = capFluentForm([[
        'element' => 'container',
        'settings' => ['conditional_logics' => ['status' => true]],
        'columns' => [['fields' => [['element' => 'input_text']]], ['fields' => [['element' => FluentForms::ELEMENT]]]],
    ]]);

    expect(capFluentForms()->validate([], [], $nested))->toHaveKey('cap-token');
});

it('survives a form row without readable fields', function (): void {
    $integration = capFluentForms();

    expect($integration->validate([], [], (object) ['id' => 1]))->toBe([])
        ->and($integration->validate([], [], (object) ['id' => 1, 'form_fields' => 'not json']))->toBe([])
        ->and($integration->validate([], [], (object) ['id' => 1, 'form_fields' => null]))->toBe([]);
});

it('verifies the token from Fluent Forms parsed submission data', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];
    $_POST['cap-token'] = 'wrong-top-level-token';

    expect(capFluentForms()->validate([], ['cap-token' => 'fluent-token'], capFluentForm()))->toBe([]);
    $request = json_decode((string) $GLOBALS['__cap_remote_last_request']['args']['body'], true);
    expect($request['response'])->toBe('fluent-token');
});

it('treats a token that is not a string as missing', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":true}'];

    expect(capFluentForms()->validate([], ['cap-token' => ['fluent-token']], capFluentForm()))->toHaveKey('cap-token');
});

it('rejects invalid tokens even when fail-open is enabled', function (): void {
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];

    expect(capFluentForms(true, true)->validate([], ['cap-token' => 'invalid'], capFluentForm()))->toHaveKey('cap-token');
});

it('applies the Fluent Forms fail-open policy to unreachable servers', function (): void {
    $GLOBALS['__cap_remote_response'] = new WP_Error('unreachable', 'unreachable');

    expect(capFluentForms(true, true)->validate([], ['cap-token' => 'token'], capFluentForm()))->toBe([])
        ->and(capFluentForms(true, false)->validate([], ['cap-token' => 'token'], capFluentForm()))->toHaveKey('cap-token');
});

it('honours the cap_captcha_protect filters', function (): void {
    add_filter('cap_captcha_protect_fluent_forms', static fn (): bool => false);

    expect(capFluentForms()->validate([], [], capFluentForm()))->toBe([])
        ->and(capFluentRender(capFluentForms()))->toBe('');
});

it('removes only the CAPTCHA field token before storing the submission', function (): void {
    $integration = capFluentForms();
    $data = ['email' => 'visitor@example.com', 'cap-token' => 'token'];

    expect($integration->removeToken($data, 1, capFluentFields()))->toBe(['email' => 'visitor@example.com'])
        ->and($integration->removeToken($data, 1, []))->toBe($data);
});

it('renders the inline widget with a unique id per form', function (): void {
    $integration = capFluentForms();
    $first = capFluentRender($integration);
    $second = capFluentRender($integration);

    preg_match('/<cap-widget id="([^"]+)"/', $first, $firstId);
    preg_match('/<cap-widget id="([^"]+)"/', $second, $secondId);

    expect($first)->toContain('class="ff-el-group cap-captcha--fluent"')
        ->and($first)->toContain('data-cap-api-endpoint="https://cap.example.com/sitekey/"')
        ->and($first)->toContain('class="cap-captcha-fluent-error text-danger" role="alert" hidden')
        ->and($firstId[1])->not->toBe($secondId[1])
        ->and($GLOBALS['__cap_modules'])->toBe([Enqueuer::WIDGET_MODULE_ID])
        ->and($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-fluent-forms')
        ->and($GLOBALS['__cap_scripts']['cap-captcha-fluent-forms']['deps'])->toBe(['jquery']);
});

it('makes the submit button the floating trigger', function (): void {
    $integration = capFluentForms(mode: Settings::MODE_FLOATING);
    $button = ['element' => 'button'];
    $html = capFluentRender($integration, ['fields' => [], 'submitButton' => $button]);
    preg_match('/<cap-widget id="([^"]+)"/', $html, $id);

    $integration->wireSubmitButton($button, (object) ['id' => 1]);
    $submit = apply_filters('fluentform/rendering_field_html_button', '<div><button type="submit">Send</button></div>');

    expect($html)->not->toContain('<button')
        ->and($html)->not->toContain('data-cap-autosubmit')
        ->and($submit)->toContain('<button data-cap-floating="#'.$id[1].'"')
        ->and($GLOBALS['__cap_modules'])->toContain(Enqueuer::FLOATING_MODULE_ID);

    // One widget, one button: a later form's button on the same page stays untouched.
    $integration->releaseSubmitButton();
    $integration->wireSubmitButton($button, (object) ['id' => 1]);
    expect(apply_filters('fluentform/rendering_field_html_button', '<button type="submit">Other</button>'))
        ->toBe('<button type="submit">Other</button>');
});

it('leaves no filter behind when the button was rendered without it', function (): void {
    $integration = capFluentForms(mode: Settings::MODE_FLOATING);
    $button = ['element' => 'button'];
    capFluentRender($integration, ['fields' => [], 'submitButton' => $button]);

    $integration->wireSubmitButton($button, (object) ['id' => 1]);
    $integration->releaseSubmitButton();

    expect(apply_filters('fluentform/rendering_field_html_button', '<button type="submit">Other form</button>'))
        ->toBe('<button type="submit">Other form</button>');
});

it('keeps its own floating trigger when the form renders no standard submit button', function (?array $fields): void {
    $html = capFluentRender(capFluentForms(mode: Settings::MODE_FLOATING), $fields);

    expect($html)->toContain('<button type="button"')
        ->and($html)->toContain('data-cap-floating="#cap-captcha-fluent-')
        ->and($html)->toContain('data-cap-autosubmit');
})->with([
    'fields unknown' => [null],
    'step form' => [['fields' => [], 'submitButton' => [], 'stepsWrapper' => ['stepStart' => []]]],
    'custom submit button' => [['fields' => [['element' => 'custom_submit_button']], 'submitButton' => []]],
]);

it('keeps its own floating trigger when a filter hides the submit button', function (): void {
    add_filter('fluentform/is_hide_submit_btn_1', fn (): bool => true);
    $html = capFluentRender(capFluentForms(mode: Settings::MODE_FLOATING), ['fields' => [], 'submitButton' => []]);

    expect($html)->toContain('data-cap-autosubmit');
});

it('leaves the submit button alone outside floating mode', function (): void {
    $integration = capFluentForms();
    capFluentRender($integration, ['fields' => [], 'submitButton' => ['element' => 'button']]);
    $integration->wireSubmitButton(['element' => 'button'], (object) ['id' => 1]);

    expect(apply_filters('fluentform/rendering_field_html_button', '<button type="submit">Send</button>'))
        ->toBe('<button type="submit">Send</button>');
});

it('renders a token field per form in programmatic mode and leaves the shared solver out', function (): void {
    $html = capFluentRender(capFluentForms(mode: Settings::MODE_PROGRAMMATIC));

    // The shared programmatic module writes one token into every form on the
    // page; Fluent Forms submits by AJAX and needs a fresh token per form.
    expect($html)->toContain('name="cap-token"')
        ->and($html)->toContain('data-cap-programmatic="1"')
        ->and($html)->toContain('window.CAP_CAPTCHA_PROGRAMMATIC={"apiEndpoint":')
        ->and($html)->not->toContain('<cap-widget')
        ->and($GLOBALS['__cap_modules'])->toBe([Enqueuer::WIDGET_MODULE_ID])
        ->and($GLOBALS['__cap_scripts'])->toHaveKey('cap-captcha-fluent-forms');
});

it('renders nothing when the surface is off or the plugin is not configured', function (): void {
    expect(capFluentRender(capFluentForms(false)))->toBe('')
        ->and(capFluentRender(capFluentForms(true, false, false)))->toBe('')
        ->and($GLOBALS['__cap_scripts'])->toBe([]);
});
