<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

final readonly class ConnectionCheckResult
{
    private function __construct(
        public bool $success,
        public string $message,
    ) {}

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
