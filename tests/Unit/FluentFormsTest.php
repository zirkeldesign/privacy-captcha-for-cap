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

/** @param array<string, mixed> $element */
function capFluentRender(FluentForms $integration, array $element = []): string
{
    ob_start();
    $integration->render($element, (object) ['id' => 1]);

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
        'fluentform/editor_element_customization_settings',
        'fluentform/editor_init_element_cap_captcha',
        'fluentform/form_input_types',
        'fluentform/render_item_cap_captcha',
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
        ->and($component['settings']['cap_display_mode'])->toBe('')
        ->and($component['editor_options']['template'])->toBe('customHTML')
        ->and($integration->addInputType(['input_text']))->toBe(['input_text', FluentForms::ELEMENT])
        ->and($integration->addEditorSettings(['input_text' => ['general' => ['label']]]))->toBe([
            'input_text' => ['general' => ['label']],
            FluentForms::ELEMENT => ['general' => ['cap_display_mode'], 'advanced' => []],
        ]);
});

it('provides the editor with a display-mode select and initializes existing fields', function (): void {
    $integration = capFluentForms();
    $options = $integration->addEditorCustomization([])['cap_display_mode'];

    expect($options['template'])->toBe('select')
        ->and(array_column($options['options'], 'value'))->toBe(['', 'inline', 'floating', 'programmatic'])
        ->and($integration->initializeField(['settings' => []])['settings']['cap_display_mode'])->toBe('')
        ->and($integration->initializeField(['settings' => ['cap_display_mode' => 'floating']])['settings']['cap_display_mode'])->toBe('floating')
        ->and($integration->initializeField(false))->toBeFalse();
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

it('renders the floating trigger and loads the floating modules', function (): void {
    $html = capFluentRender(capFluentForms(mode: Settings::MODE_FLOATING));

    expect($html)->toContain('data-cap-floating="#cap-captcha-fluent-')
        ->and($html)->toContain('data-cap-autosubmit')
        ->and($GLOBALS['__cap_modules'])->toContain(Enqueuer::FLOATING_MODULE_ID);
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

it('uses the field display mode or falls back to the global setting', function (string $global, mixed $override, string $expected): void {
    $html = capFluentRender(capFluentForms(mode: $global), ['settings' => ['cap_display_mode' => $override]]);

    expect($html)->toContain($expected);
})->with([
    'global inline' => [Settings::MODE_INLINE, '', 'cap-captcha--inline'],
    'global floating' => [Settings::MODE_FLOATING, '', 'cap-captcha--floating'],
    'global programmatic' => [Settings::MODE_PROGRAMMATIC, '', 'data-cap-programmatic="1"'],
    'override inline' => [Settings::MODE_PROGRAMMATIC, Settings::MODE_INLINE, 'cap-captcha--inline'],
    'override floating' => [Settings::MODE_INLINE, Settings::MODE_FLOATING, 'cap-captcha--floating'],
    'override programmatic' => [Settings::MODE_INLINE, Settings::MODE_PROGRAMMATIC, 'data-cap-programmatic="1"'],
    'invalid override' => [Settings::MODE_FLOATING, 'unknown', 'cap-captcha--floating'],
    'non-string override' => [Settings::MODE_INLINE, ['programmatic'], 'cap-captcha--inline'],
]);

it('provides programmatic configuration alongside another display mode', function (bool $programmaticFirst): void {
    $integration = capFluentForms(mode: Settings::MODE_INLINE);
    $element = ['settings' => ['cap_display_mode' => Settings::MODE_PROGRAMMATIC]];
    if ($programmaticFirst) {
        $programmatic = capFluentRender($integration, $element);
        $inline = capFluentRender($integration);
    } else {
        $inline = capFluentRender($integration);
        $programmatic = capFluentRender($integration, $element);
    }

    expect($inline)->toContain('cap-captcha--inline')
        ->and($programmatic)->toContain('data-cap-programmatic="1"')
        ->and($inline.$programmatic)->toContain('window.CAP_CAPTCHA_PROGRAMMATIC={"apiEndpoint":')
        ->and(substr_count($inline.$programmatic, 'window.CAP_CAPTCHA_PROGRAMMATIC='))->toBe(1)
        ->and($GLOBALS['__cap_modules'])->not->toContain(Enqueuer::PROGRAMMATIC_MODULE_ID);
})->with([false, true]);

it('renders nothing when the surface is off or the plugin is not configured', function (): void {
    expect(capFluentRender(capFluentForms(false)))->toBe('')
        ->and(capFluentRender(capFluentForms(true, false, false)))->toBe('')
        ->and($GLOBALS['__cap_scripts'])->toBe([]);
});
