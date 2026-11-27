<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Settings;

/**
 * A block theme prints script modules in the head. A widget rendered later,
 * such as an Elementor form in a popup printed in the footer, enqueued the
 * widget module too late: the element was on the page and never started.
 */
beforeEach(function (): void {
    cap_reset_options();
    cap_reset_filters();
    $GLOBALS['__cap_modules'] = [];
    $GLOBALS['__cap_modules_printed'] = 0;
    $GLOBALS['__cap_block_theme'] = true;
    $GLOBALS['__cap_did_actions'] = ['wp_head' => 1];
    $GLOBALS['__cap_doing_actions'] = [];
});

afterEach(function (): void {
    $GLOBALS['__cap_block_theme'] = false;
    $GLOBALS['__cap_did_actions'] = [];
    $GLOBALS['__cap_doing_actions'] = [];
});

function capLateFooterHooks(): array
{
    return $GLOBALS['__cap_filters']['wp_footer'] ?? [];
}

it('prints the modules in the footer when a block theme has already printed its head', function (): void {
    $enqueuer = new Enqueuer(new Settings);
    $enqueuer->enqueueForMode(Settings::MODE_FLOATING);
    $hooks = capLateFooterHooks();
    ($hooks[0]['cb'])();

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]['priority'])->toBe(20)
        ->and($GLOBALS['__cap_modules_printed'])->toBe(1);
});

it('prints the modules again on a classic theme when the widget is rendered inside the footer', function (): void {
    $GLOBALS['__cap_block_theme'] = false;
    $GLOBALS['__cap_did_actions'] = [];
    $GLOBALS['__cap_doing_actions'] = ['wp_footer'];
    (new Enqueuer(new Settings))->enqueueForMode(Settings::MODE_INLINE);

    expect(capLateFooterHooks())->toHaveCount(1);
});

it('leaves printing to WordPress when its own pass is still to come', function (array $state): void {
    foreach ($state as $key => $value) {
        $GLOBALS[$key] = $value;
    }
    (new Enqueuer(new Settings))->enqueueForMode(Settings::MODE_INLINE);

    expect(capLateFooterHooks())->toBe([]);
})->with([
    'a classic theme, before its footer' => [['__cap_block_theme' => false]],
    'the head has not run yet' => [['__cap_did_actions' => []]],
    'enqueued from inside the head' => [['__cap_doing_actions' => ['wp_head']]],
]);
