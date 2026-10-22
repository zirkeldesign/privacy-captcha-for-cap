<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Status\KeyProtocol;

it('reads a hashwx key as Cap Standalone 3.1 creates it', function (): void {
    // keyDefaults in standalone/src/server.js.
    $protocol = KeyProtocol::fromConfig([
        'difficulty' => 4,
        'challengeCount' => 80,
        'saltSize' => 32,
        'protocol' => 'hashwx',
        'hashwxDifficulty' => 1_000_000,
    ]);

    expect($protocol->name)->toBe(KeyProtocol::HASHWX)
        ->and($protocol->isHashwx())->toBeTrue()
        ->and($protocol->difficulty)->toBe(1_000_000)
        ->and($protocol->solverFile())->toBe('hashwx.wasm');
});

it('falls back to the capjs-core default when a hashwx key has no difficulty', function (): void {
    $protocol = KeyProtocol::fromConfig(['protocol' => 'hashwx']);

    expect($protocol->difficulty)->toBe(1_000_000);
});

it('treats a key without a protocol field as sha256-pow', function (): void {
    // A key created before Cap 3.1 has no `protocol` entry at all.
    $protocol = KeyProtocol::fromConfig([
        'difficulty' => 4,
        'challengeCount' => 50,
        'saltSize' => 32,
    ]);

    expect($protocol->name)->toBe(KeyProtocol::SHA256)
        ->and($protocol->isHashwx())->toBeFalse()
        ->and($protocol->difficulty)->toBe(4)
        ->and($protocol->challengeCount)->toBe(50)
        ->and($protocol->saltSize)->toBe(32)
        ->and($protocol->solverFile())->toBe('cap_wasm_bg.wasm');
});

it('reads a sha256-pow key that names its protocol', function (): void {
    $protocol = KeyProtocol::fromConfig([
        'protocol' => 'sha256-pow',
        'difficulty' => 5,
        'hashwxDifficulty' => 1_000_000,
    ]);

    expect($protocol->name)->toBe(KeyProtocol::SHA256)
        ->and($protocol->difficulty)->toBe(5);
});

it('recognises a legacy rsw key by its flag', function (): void {
    $protocol = KeyProtocol::fromConfig(['rsw' => true, 'rswT' => 75_000, 'difficulty' => 4]);

    expect($protocol->name)->toBe(KeyProtocol::RSW)
        ->and($protocol->difficulty)->toBe(75_000)
        ->and($protocol->solverFile())->toBe('cap_wasm_bg.wasm');
});

it('keeps the name of a protocol it does not know and sizes it at zero', function (): void {
    $protocol = KeyProtocol::fromConfig(['protocol' => 'something-newer', 'difficulty' => 4]);

    expect($protocol->name)->toBe('something-newer')
        ->and($protocol->difficulty)->toBe(0);
});

it('reads an empty config as a sha256-pow key with no figures', function (): void {
    $protocol = KeyProtocol::fromConfig([]);

    expect($protocol->name)->toBe(KeyProtocol::SHA256)
        ->and($protocol->difficulty)->toBe(0)
        ->and($protocol->challengeCount)->toBe(0)
        ->and($protocol->saltSize)->toBe(0);
});
