<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

/**
 * The rules of the site key that decide how forms are rendered, kept in an
 * option so that a page view reads them without a request to the Cap server.
 *
 * StatsClient writes it each time it reads the key through the API, which
 * the settings page, the dashboard widget and an hourly event do. Without an
 * API key nothing is ever stored and no rule applies.
 */
final class StoredKeyPolicy
{
    public const OPTION = 'cap_captcha_key_policy';

    /**
     * @param  array<string, mixed>  $stats  A /server/keys/<siteKey> payload.
     */
    public function remember(array $stats): void
    {
        $config = is_array($stats['key']['config'] ?? null) ? $stats['key']['config'] : [];

        update_option(self::OPTION, [
            'requires_interaction' => KeyPolicy::fromConfig($config)->requiresInteraction,
        ]);
    }

    public function requiresInteraction(): bool
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) && ! empty($stored['requires_interaction']);
    }

    /**
     * Whether the rules of the key were ever read. They are not without an
     * API key, nor while the Cap server refuses it or does not answer.
     */
    public function isKnown(): bool
    {
        $stored = get_option(self::OPTION);

        return is_array($stored) && array_key_exists('requires_interaction', $stored);
    }

    public function forget(): void
    {
        delete_option(self::OPTION);
    }
}
