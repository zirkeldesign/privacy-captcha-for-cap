<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Integration\ElementorPro;
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

function capElementor(bool $enabled = true, string $mode = Settings::MODE_INLINE, bool $failOpen = false): ElementorPro
{
    update_option(Settings::OPTION_KEY, [
        'endpoint_base' => 'https://cap.example.com/',
        'site_key' => 'sitekey',
        'secret_key' => 'secret',
        'display_mode' => $mode,
        'fail_open' => $failOpen,
        'integrations' => ['elementor_pro' => $enabled],
    ]);
    $settings = new Settings;

    return new ElementorPro($settings, new Renderer($settings), new Enqueuer($settings), new TokenVerifier($settings));
}

/**
 * The record as Elementor Pro builds it: the fields come from the stored
 * widget, the request only fills in their values.
 *
 * @param  array<int, array<string, mixed>>|null  $fields  Defaults to a form holding a text field and the CAPTCHA field.
 */
function capElementorRecord(?array $fields = null): object
{
    $fields ??= [
        ['custom_id' => 'name', 'field_type' => 'text'],
        ['custom_id' => 'captcha', 'field_type' => ElementorPro::FIELD_TYPE],
    ];

    return new class($fields)
    {
        /** @var array<int, string> */
        public array $removed = [];

        /** @param array<int, array<string, mixed>> $fields */
        public function __construct(private array $fields) {}

        public function get_form_settings(string $setting): mixed
        {
            return ['form_fields' => $this->fields, 'id' => 'abc123'][$setting] ?? null;
        }

        public function remove_field(string $id): void
        {
            $this->removed[] = $id;
        }
    };
}

/** Elementor Pro's Ajax_Handler, as far as a validator touches it. */
function capElementorHandler(array $errors = []): object
{
    return new class($errors)
    {
        public bool $is_success = true;

        /** @param array<string, string> $errors */
        public function __construct(public array $errors)
        {
            $this->is_success = $errors === [];
        }

        public function add_error(string $field, string $message = ''): static
        {
            $this->errors[$field] = $message;
            $this->is_success = false;

            return $this;
        }

        public function set_success(bool $success): void
        {
            $this->is_success = $success;
        }
    };
}

function capElementorRender(ElementorPro $integration): string
{
    ob_start();
    $integration->render(['custom_id' => 'captcha', 'field_type' => ElementorPro::FIELD_TYPE]);

    return (string) ob_get_clean();
}

it('identifies itself and is unavailable without Elementor Pro', function (): void {
    $integration = capElementor();

    expect($integration->id())->toBe('elementor_pro')
        ->and(Settings::SURFACES)->toContain('elementor_pro')
        ->and($integration->isAvailable())->toBeFalse();
});

it('registers the field type, renderer and validation hooks', function (): void {
    capElementor()->register();

    expect($GLOBALS['__cap_filters'])->toHaveKeys([
        'elementor_pro/forms/field_types',
        'elementor_pro/forms/render/item',
        'elementor_pro/forms/render_field/cap_captcha',
        'elementor/element/is_dynamic_content',
        'elementor/widget/render_content',
        'elementor_pro/forms/validation',
        'elementor_pro/forms/new_record',
    ]);
});

it('adds the field type and strips label and required from its item only', function (): void {
    $integration = capElementor();

    expect($integration->addFieldType(['text' => 'Text']))->toHaveKeys(['text', ElementorPro::FIELD_TYPE])
        ->and($integration->prepareItem(['field_type' => ElementorPro::FIELD_TYPE, 'field_label' => 'x', 'required' => true]))
        ->toMatchArray(['field_label' => false, 'required' => false])
        ->and($integration->prepareItem(['field_type' => 'text', 'field_label' => 'x', 'required' => true]))
        ->toMatchArray(['field_label' => 'x', 'required' => true]);
});

it('refuses the smallest submission Elementor accepts when it carries no token', function (): void {
    $handler = capElementorHandler();
    capElementor()->validate($record = capElementorRecord(), $handler);

    expect($handler->errors)->toHaveKey('captcha')
        ->and($handler->is_success)->toBeFalse()
        ->and($record->removed)->toBe([]);
});

it('refuses a token Cap rejects', function (): void {
    $_POST['cap-token'] = 'spent';
    $GLOBALS['__cap_remote_response'] = ['body' => '{"success":false}'];
    $handler = capElementorHandler();
    capElementor()->validate(capElementorRecord(), $handler);

    expect($handler->errors)->toHaveKey('captcha');
});

it('accepts a verified token and keeps the field out of mails and submissions', function (): void {
    $_POST['cap-token'] = 'solved';
    $handler = capElementorHandler();
    capElementor()->validate($record = capElementorRecord(), $handler);

    expect($handler->errors)->toBe([])
        ->and($handler->is_success)->toBeTrue()
        ->and($record->removed)->toBe(['captcha']);
});

it('reads the field from the stored form, so a request cannot leave it out', function (): void {
    // The request names no CAPTCHA field at all: only the stored widget counts.
    $_POST = ['form_fields' => ['name' => 'Ada']];
    $handler = capElementorHandler();
    capElementor()->validate(capElementorRecord(), $handler);

    expect($handler->errors)->toHaveKey('captcha');
});

it('leaves a form without the field, and a switched-off integration, alone', function (): void {
    $plain = capElementorHandler();
    capElementor()->validate(capElementorRecord([['custom_id' => 'name', 'field_type' => 'text']]), $plain);
    $off = capElementorHandler();
    capElementor(false)->validate(capElementorRecord(), $off);

    expect($plain->errors)->toBe([])
        ->and($off->errors)->toBe([])
        ->and($GLOBALS['__cap_remote_post_count'] ?? 0)->toBe(0);
});

it('does not spend the token on a submission Elementor already refused', function (): void {
    $_POST['cap-token'] = 'solved';
    $handler = capElementorHandler(['name' => 'This field is required.', 'captcha' => 'This field is required.']);
    capElementor()->validate($record = capElementorRecord(), $handler);

    expect($handler->errors)->toBe(['name' => 'This field is required.'])
        ->and($handler->is_success)->toBeFalse()
        ->and($GLOBALS['__cap_remote_post_count'] ?? 0)->toBe(0)
        ->and($record->removed)->toBe([]);
});

it('replaces Elementor\'s "required" verdict on the field with the token\'s', function (): void {
    $refused = capElementorHandler(['captcha' => 'This field is required.']);
    capElementor()->validate(capElementorRecord(), $refused);

    $_POST['cap-token'] = 'solved';
    $passed = capElementorHandler(['captcha' => 'This field is required.']);
    capElementor()->validate(capElementorRecord(), $passed);

    expect($refused->errors['captcha'])->toBe('CAPTCHA verification failed. Please try again.')
        ->and($passed->errors)->toBe([])
        ->and($passed->is_success)->toBeTrue();
});

it('fires the fail-open action only for a submission that passed on an unreachable Cap', function (): void {
    $GLOBALS['__cap_actions'] = [];
    $_POST['cap-token'] = 'solved';

    $integration = capElementor();
    $integration->validate($record = capElementorRecord(), capElementorHandler());
    $integration->annotateFailOpen($record);

    $GLOBALS['__cap_remote_response'] = new WP_Error('unreachable', 'unreachable');
    $integration = capElementor(true, Settings::MODE_INLINE, true);
    $integration->validate($record = capElementorRecord(), $handler = capElementorHandler());
    $integration->annotateFailOpen($record);
    $integration->annotateFailOpen($record);

    expect($handler->errors)->toBe([])
        ->and($GLOBALS['__cap_actions']['cap_captcha_fail_open_pass'])->toBe([['elementor_pro', ['form_id' => 'abc123']]]);
});

it('keeps a form holding the field out of Elementor\'s element cache, and only that', function (): void {
    $integration = capElementor();
    $form = static fn (string $type): array => ['widgetType' => 'form', 'settings' => ['form_fields' => [['field_type' => $type]]]];

    expect($integration->renderOnEveryRequest(false, $form(ElementorPro::FIELD_TYPE)))->toBeTrue()
        ->and($integration->renderOnEveryRequest(false, $form('text')))->toBeFalse()
        ->and($integration->renderOnEveryRequest(false, ['widgetType' => 'heading', 'settings' => []]))->toBeFalse()
        ->and($integration->renderOnEveryRequest(true, $form('text')))->toBeTrue()
        ->and($integration->renderOnEveryRequest(false, null))->toBeFalse();
});

it('renders the inline widget with its error box, and nothing when switched off', function (): void {
    $html = capElementorRender(capElementor());

    expect($html)->toContain('cap-captcha--elementor')
        ->and($html)->toContain('<cap-widget')
        ->and($html)->toContain('cap-captcha-elementor-error')
        ->and(capElementorRender(capElementor(false)))->toBe('');
});

it('turns the form\'s own submit button into the floating trigger', function (): void {
    $integration = capElementor(true, Settings::MODE_FLOATING);
    $field = capElementorRender($integration);
    $content = $integration->wireSubmitButton('<form>'.$field.'<button class="elementor-button" type="submit">Send</button></form>');

    expect($field)->not->toContain('<cap-widget')
        ->and($content)->toContain('<cap-widget')
        ->and($content)->toMatch('/<button[^>]*data-cap-floating="#cap-captcha-elementor-[^"]+"[^>]*type="submit"|<button[^>]*type="submit"[^>]*data-cap-floating="#cap-captcha-elementor-/')
        ->and($content)->not->toContain('<!--cap-captcha-elementor-');
});

it('gives the floating widget a trigger of its own when the form has no submit button', function (): void {
    $integration = capElementor(true, Settings::MODE_FLOATING);
    $field = capElementorRender($integration);
    $untouched = $integration->wireSubmitButton('<div>another widget</div>');
    $content = $integration->wireSubmitButton('<form>'.$field.'</form>');

    expect($untouched)->toBe('<div>another widget</div>')
        ->and($content)->toContain('<cap-widget')
        ->and($content)->toContain('data-cap-floating')
        ->and($content)->not->toContain('<!--cap-captcha-elementor-');
});
