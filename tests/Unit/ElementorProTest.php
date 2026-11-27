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
    $GLOBALS['__cap_posts'] = [];
    $GLOBALS['__cap_caps'] = [];
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
function capElementorRecord(?array $fields = null, int $postId = 0): object
{
    $fields ??= [
        ['custom_id' => 'name', 'field_type' => 'text'],
        ['custom_id' => 'captcha', 'field_type' => ElementorPro::FIELD_TYPE],
    ];

    return new class($fields, $postId)
    {
        /** @var array<int, string> */
        public array $removed = [];

        /** @param array<int, array<string, mixed>> $fields */
        public function __construct(private array $fields, private int $postId) {}

        public function get_form_settings(string $setting): mixed
        {
            // Elementor copies the request's post_id here, unchecked.
            return ['form_fields' => $this->fields, 'id' => 'abc123', 'edit_post_id' => $this->postId ?: null][$setting] ?? null;
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

        /** @var array<string, array<int, string>> */
        public array $messages = ['success' => [], 'error' => [], 'admin_error' => []];

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
        'elementor_pro/forms/validation/skip_all_strip_types',
        'elementor_pro/forms/render_field/cap_captcha',
        'elementor/element/is_dynamic_content',
        'elementor/widget/render_content',
        'elementor_pro/forms/validation',
        'elementor_pro/forms/new_record',
    ]);
});

it('adds the field type, hides the label of its item only and joins the types Elementor strips', function (): void {
    $integration = capElementor();

    expect($integration->addFieldType(['text' => 'Text']))->toHaveKeys(['text', ElementorPro::FIELD_TYPE])
        ->and($integration->prepareItem(['field_type' => ElementorPro::FIELD_TYPE, 'field_label' => 'x']))->toMatchArray(['field_label' => false])
        ->and($integration->prepareItem(['field_type' => 'text', 'field_label' => 'x']))->toMatchArray(['field_label' => 'x'])
        ->and($integration->addStripType(['recaptcha']))->toBe(['recaptcha', ElementorPro::FIELD_TYPE]);
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

/*
 * Elementor loads the form from whichever post the request names. A revision
 * saved before the field was added still carries the form and its actions,
 * and Elementor keeps one for every save.
 */
it('refuses a copy of the page that visitors cannot see, with or without the field in it', function (array $post, array $fields): void {
    $GLOBALS['__cap_posts'] = [5 => ['post_status' => 'publish', 'post_type' => 'page', 'post_parent' => 0], 14 => $post];
    $_POST['cap-token'] = 'solved';
    $handler = capElementorHandler();
    capElementor()->validate($record = capElementorRecord($fields, 14), $handler);

    expect($handler->errors)->toHaveKey(ElementorPro::FIELD_TYPE)
        ->and($handler->is_success)->toBeFalse()
        ->and($record->removed)->toBe([])
        ->and($GLOBALS['__cap_remote_post_count'] ?? 0)->toBe(0);
})->with([
    'a revision from before the field was added' => [['post_status' => 'inherit', 'post_type' => 'revision', 'post_parent' => 5], [['custom_id' => 'name', 'field_type' => 'text']]],
    'a revision that has the field' => [['post_status' => 'inherit', 'post_type' => 'revision', 'post_parent' => 5], [['custom_id' => 'captcha', 'field_type' => ElementorPro::FIELD_TYPE]]],
    'a draft copy' => [['post_status' => 'draft', 'post_type' => 'page', 'post_parent' => 0], [['custom_id' => 'name', 'field_type' => 'text']]],
    'a page in the trash' => [['post_status' => 'trash', 'post_type' => 'page', 'post_parent' => 0], [['custom_id' => 'name', 'field_type' => 'text']]],
]);

it('lets someone who may see the hidden copy submit it, and checks the published page as usual', function (): void {
    $GLOBALS['__cap_posts'] = [
        5 => ['post_status' => 'publish', 'post_type' => 'page', 'post_parent' => 0],
        14 => ['post_status' => 'inherit', 'post_type' => 'revision', 'post_parent' => 5],
        15 => ['post_status' => 'draft', 'post_type' => 'page', 'post_parent' => 0],
    ];
    $GLOBALS['__cap_caps'] = ['edit_post:5', 'read_post:15'];
    $plain = [['custom_id' => 'name', 'field_type' => 'text']];

    $revision = capElementorHandler();
    capElementor()->validate(capElementorRecord($plain, 14), $revision);
    $draft = capElementorHandler();
    capElementor()->validate(capElementorRecord($plain, 15), $draft);
    $published = capElementorHandler();
    capElementor()->validate(capElementorRecord(null, 5), $published);

    expect($revision->errors)->toBe([])
        ->and($draft->errors)->toBe([])
        ->and($published->errors)->toHaveKey('captcha');
});

it('does not restore success over a validator that failed the submission with a message only', function (): void {
    $_POST['cap-token'] = 'solved';
    $handler = capElementorHandler(['captcha' => 'This field is required.']);
    $handler->messages = ['error' => ['Blocked by another check.'], 'admin_error' => []];
    capElementor()->validate(capElementorRecord(), $handler);

    expect($handler->errors)->toBe([])
        ->and($handler->is_success)->toBeFalse();
});

/*
 * The editor offers "Required" for the field, and Elementor then fails every
 * submission for it, because the field never posts a value of its own.
 */
it('does not leave a form unsendable when the field is marked required and protection is switched off', function (): void {
    $handler = capElementorHandler(['captcha' => 'This field is required.']);
    capElementor(false)->validate($record = capElementorRecord(), $handler);

    expect($handler->errors)->toBe([])
        ->and($handler->is_success)->toBeTrue()
        ->and($record->removed)->toBe(['captcha'])
        ->and($GLOBALS['__cap_remote_post_count'] ?? 0)->toBe(0);
});

it('treats a second CAPTCHA field in the form like the first', function (): void {
    $fields = [
        ['custom_id' => 'captcha', 'field_type' => ElementorPro::FIELD_TYPE],
        ['custom_id' => 'captcha_two', 'field_type' => ElementorPro::FIELD_TYPE],
    ];
    $refused = capElementorHandler(['captcha_two' => 'This field is required.']);
    capElementor()->validate(capElementorRecord($fields), $refused);

    $_POST['cap-token'] = 'solved';
    $passed = capElementorHandler(['captcha_two' => 'This field is required.']);
    capElementor()->validate($record = capElementorRecord($fields), $passed);

    expect($refused->errors)->toBe(['captcha' => 'CAPTCHA verification failed. Please try again.'])
        ->and($passed->errors)->toBe([])
        ->and($passed->is_success)->toBeTrue()
        ->and($record->removed)->toBe(['captcha', 'captcha_two']);
});

it('renders a widget for the first CAPTCHA field of a form only', function (): void {
    $integration = capElementor();
    $widget = new class
    {
        public function get_settings(string $key): mixed
        {
            return [['custom_id' => 'one', 'field_type' => ElementorPro::FIELD_TYPE], ['custom_id' => 'two', 'field_type' => ElementorPro::FIELD_TYPE]];
        }
    };
    $render = static function (string $id) use ($integration, $widget): string {
        ob_start();
        $integration->render(['custom_id' => $id, 'field_type' => ElementorPro::FIELD_TYPE], 0, $widget);

        return (string) ob_get_clean();
    };

    expect($render('one'))->toContain('<cap-widget')
        ->and($render('two'))->toBe('');
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
        ->and($html)->toContain('id="form-field-captcha"')
        ->and($html)->toContain('<cap-widget')
        ->and($html)->toContain('cap-captcha-elementor-error')
        ->and(capElementorRender(capElementor(false)))->toBe('');
});

it('draws a placeholder instead of the widget wherever the editor renders the form', function (string $hook): void {
    $GLOBALS['__cap_did_actions'] = [$hook => 1];
    $html = capElementorRender(capElementor());
    $GLOBALS['__cap_did_actions'] = [];

    expect($html)->toContain('Privacy CAPTCHA for Cap')
        ->and($html)->not->toContain('<cap-widget');
})->with(['elementor/editor/init', 'elementor/preview/enqueue_scripts', 'elementor/ajax/register_actions']);

it('does not mistake a button that only names submit in a data attribute for the submit button', function (): void {
    $integration = capElementor(true, Settings::MODE_FLOATING);
    $field = capElementorRender($integration);
    $content = $integration->wireSubmitButton('<form><button type="button" data-type="submit">Next</button>'.$field.'<button type="submit">Send</button></form>');

    expect($content)->toContain('<button type="button" data-type="submit">Next</button>')
        ->and($content)->toMatch('/<button[^>]*data-cap-floating[^>]*>Send|<button[^>]*type="submit"[^>]*data-cap-floating/');
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
