<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

interface Integration
{
    public function id(): string;

    public function isAvailable(): bool;

    public function register(): void;
}
