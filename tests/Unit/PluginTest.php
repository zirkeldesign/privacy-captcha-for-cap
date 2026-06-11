<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Integration\GravityForms;

it('detects forms that contain a cap_captcha field', function (): void {
    $form = [
        'fields' => [
            (object) ['type' => 'text'],
            (object) ['type' => 'cap_captcha'],
        ],
    ];

    expect(GravityForms::formHasCapField($form))->toBeTrue();
});

it('returns false when no Cap field is present', function (): void {
    $form = [
        'fields' => [
            (object) ['type' => 'text'],
            (object) ['type' => 'email'],
        ],
    ];

    expect(GravityForms::formHasCapField($form))->toBeFalse();
});

it('handles empty or missing fields gracefully', function (): void {
    expect(GravityForms::formHasCapField([]))->toBeFalse();
    expect(GravityForms::formHasCapField(['fields' => []]))->toBeFalse();
});

it('exposes a stable module id for the widget', function (): void {
    expect(Enqueuer::WIDGET_MODULE_ID)->toBe('cap-captcha/widget');
    expect(Enqueuer::FLOATING_MODULE_ID)->toBe('cap-captcha/floating');
    expect(Enqueuer::PROGRAMMATIC_MODULE_ID)->toBe('cap-captcha/programmatic');
});
