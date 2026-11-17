<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

final readonly class ConnectionCheckResult
{
    /**
     * @param  list<string>  $warnings  Things that work less well than they could, on a check that passed.
     */
    private function __construct(
        public bool $success,
        public string $message,
        public array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function ok(string $message, array $warnings = []): self
    {
        return new self(true, $message, $warnings);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
