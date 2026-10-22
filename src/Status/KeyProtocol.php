<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

/**
 * The challenge protocol of a Cap site key and the one number that sizes it.
 *
 * Cap Standalone stores every key with a `config` object. Keys created on
 * 3.1 or newer carry `protocol` ("hashwx" by default) and `hashwxDifficulty`;
 * older keys have no `protocol` field and are SHA-256 proof-of-work, or RSW
 * when their `rsw` flag is set. Each protocol measures its cost differently,
 * so the figure a dashboard should call "difficulty" depends on the protocol:
 *
 *  - sha256-pow: `difficulty`, the number of leading hex zeros each of the
 *    `challengeCount` sub-challenges must reach, over a `saltSize`-byte salt.
 *  - hashwx: `hashwxDifficulty`, the expected total number of hashes per
 *    solve.
 *  - rsw: `rswT`, the number of sequential squarings of the time-lock puzzle.
 */
final class KeyProtocol
{
    public const SHA256 = 'sha256-pow';

    public const HASHWX = 'hashwx';

    public const RSW = 'rsw';

    /** capjs-core's default when a hashwx key carries no explicit difficulty. */
    private const HASHWX_DEFAULT_DIFFICULTY = 1_000_000;

    private function __construct(
        public readonly string $name,
        public readonly int $difficulty,
        public readonly int $challengeCount,
        public readonly int $saltSize,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `key.config` object of the Cap API.
     */
    public static function fromConfig(array $config): self
    {
        $name = $config['protocol'] ?? null;
        if (! is_string($name) || $name === '') {
            $name = ! empty($config['rsw']) ? self::RSW : self::SHA256;
        }

        $difficulty = match ($name) {
            self::HASHWX => (int) ($config['hashwxDifficulty'] ?? self::HASHWX_DEFAULT_DIFFICULTY),
            self::RSW => (int) ($config['rswT'] ?? 0),
            self::SHA256 => (int) ($config['difficulty'] ?? 0),
            default => 0,
        };

        return new self(
            $name,
            $difficulty,
            (int) ($config['challengeCount'] ?? 0),
            (int) ($config['saltSize'] ?? 0),
        );
    }

    public function isHashwx(): bool
    {
        return $this->name === self::HASHWX;
    }

    /**
     * The solver file the widget needs for this protocol, as named under the
     * plugin's assets/wasm/ directory and the Cap server's /assets/ route.
     * hashwx has its own WebAssembly module; everything else runs on the
     * SHA-256 module, which the widget also uses for RSW.
     */
    public function solverFile(): string
    {
        return $this->isHashwx() ? 'hashwx.wasm' : 'cap_wasm_bg.wasm';
    }
}
