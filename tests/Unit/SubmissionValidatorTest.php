<?php

declare(strict_types=1);

use ZirkelDesign\GFCapCaptcha\Settings;
use ZirkelDesign\GFCapCaptcha\Validation\SubmissionValidator;

beforeEach(function (): void {
    $_POST = [];
});

function capFakeSettings(): Settings
{
    return new class extends Settings
    {
        public function __construct() {}

        public function getEndpointBase(): string
        {
            return 'https://cap.example.com/';
        }

        public function getSiteKey(): string
        {
            return 'sitekey';
        }

        public function getSecretKey(): string
        {
            return 'secret';
        }
    };
}

function capCaptchaField(int $id = 4): object
{
    return (object) [
        'id'                 => $id,
        'type'               => 'cap_captcha',
        'failed_validation'  => false,
        'validation_message' => '',
    ];
}

function capForm(object ...$fields): array
{
    return [
        'is_valid' => true,
        'form'     => [
            'id'     => 1,
            'fields' => $fields,
        ],
    ];
}

it('passes through forms without a Cap field', function (): void {
    $result = capForm((object) ['id' => 1, 'type' => 'text']);

    $validator = new SubmissionValidator(static fn () => capFakeSettings());

    expect($validator->validate($result))->toBe($result);
});

it('fails when the cap-token is missing', function (): void {
    $field = capCaptchaField();
    $validator = new SubmissionValidator(static fn () => capFakeSettings());

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeFalse();
    expect($field->failed_validation)->toBeTrue();
    expect($field->validation_message)->not->toBe('');
});

it('fails when the verifier rejects the token', function (): void {
    $_POST['cap-token'] = 'bad-token';

    $field = capCaptchaField();
    $validator = new SubmissionValidator(
        static fn () => capFakeSettings(),
        static fn (string $token, Settings $settings): bool => false,
    );

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeFalse();
    expect($field->failed_validation)->toBeTrue();
});

it('passes when the verifier accepts the token', function (): void {
    $_POST['cap-token'] = 'good-token';

    $field = capCaptchaField();
    $validator = new SubmissionValidator(
        static fn () => capFakeSettings(),
        static fn (string $token, Settings $settings): bool => true,
    );

    $result = $validator->validate(capForm($field));

    expect($result['is_valid'])->toBeTrue();
    expect($field->failed_validation)->toBeFalse();
});
