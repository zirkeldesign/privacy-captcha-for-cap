<?php

declare(strict_types=1);

use ZirkelDesign\GFCapCaptcha\Plugin;

it('detects forms that contain a cap_captcha field', function (): void {
    $form = [
        'fields' => [
            (object) ['type' => 'text'],
            (object) ['type' => 'cap_captcha'],
        ],
    ];

    expect(Plugin::formHasCapField($form))->toBeTrue();
});

it('returns false when no Cap field is present', function (): void {
    $form = [
        'fields' => [
            (object) ['type' => 'text'],
            (object) ['type' => 'email'],
        ],
    ];

    expect(Plugin::formHasCapField($form))->toBeFalse();
});

it('handles empty or missing fields gracefully', function (): void {
    expect(Plugin::formHasCapField([]))->toBeFalse();
    expect(Plugin::formHasCapField(['fields' => []]))->toBeFalse();
});

it('exposes a stable module id for the widget', function (): void {
    expect(Plugin::WIDGET_MODULE_ID)->toBe('cap-captcha-for-gravity-forms/widget');
});
