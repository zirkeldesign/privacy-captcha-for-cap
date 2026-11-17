<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Status\KeyPolicy;

it('asks nothing of a key with the Cap defaults', function (): void {
    $policy = KeyPolicy::fromConfig([
        'behavior' => 'off',
        'blockAgents' => 'off',
        'blockAutomatedInteractions' => false,
        'requireInteraction' => false,
    ]);

    expect($policy->rejectsIsolatedFrames)->toBeFalse()
        ->and($policy->requiresInteraction)->toBeFalse();
});

it('asks nothing of a key from before Cap 4, which has none of the fields', function (): void {
    $policy = KeyPolicy::fromConfig(['difficulty' => 4, 'challengeCount' => 50]);

    expect($policy->rejectsIsolatedFrames)->toBeFalse()
        ->and($policy->requiresInteraction)->toBeFalse();
});

it('rejects isolated frames as soon as one rule blocks on what the page shows', function (array $config): void {
    expect(KeyPolicy::fromConfig($config)->rejectsIsolatedFrames)->toBeTrue();
})->with([
    'behavior' => [['behavior' => 'block']],
    'automated interactions' => [['blockAutomatedInteractions' => true]],
    'scripted clicks signal' => [['signalActions' => ['interaction_synthetic_clicks' => 'block']]],
    'scripted key presses signal' => [['signalActions' => ['interaction_synthetic_keydowns' => 'block']]],
    'isolated frame signal' => [['signalActions' => ['isolated_frame' => 'block']]],
]);

it('accepts isolated frames while the key only reports, or blocks AI agents', function (): void {
    $policy = KeyPolicy::fromConfig(['behavior' => 'report', 'detectAutomatedInteractions' => true, 'blockAgents' => 'all']);

    expect($policy->rejectsIsolatedFrames)->toBeFalse();
});

it('ignores the signals an isolated frame still answers', function (): void {
    $policy = KeyPolicy::fromConfig(['signalActions' => ['csp_eval_blocked' => 'block', 'interaction_synthetic_clicks' => 'flag']]);

    expect($policy->rejectsIsolatedFrames)->toBeFalse();
});

it('asks nothing of a key with instrumentation off, whatever rules it still carries', function (): void {
    $policy = KeyPolicy::fromConfig(['instrumentation' => false, 'blockAutomatedInteractions' => true, 'behavior' => 'block', 'requireInteraction' => true]);

    expect($policy->rejectsIsolatedFrames)->toBeFalse()
        ->and($policy->requiresInteraction)->toBeFalse();
});

it('requires a press only when behavior blocks', function (): void {
    expect(KeyPolicy::fromConfig(['behavior' => 'block', 'requireInteraction' => true])->requiresInteraction)->toBeTrue()
        ->and(KeyPolicy::fromConfig(['behavior' => 'report', 'requireInteraction' => true])->requiresInteraction)->toBeFalse();
});
