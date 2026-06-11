<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * Fetches the Cap server's `/server/keys/<siteKey>` payload and caches it via
 * a 5-minute transient so the settings page doesn't hit the API on every load.
 */
final class StatsClient
{
    public const CACHE_KEY = 'cap_captcha_stats';

    public const CACHE_TTL = 300;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return array<string, mixed>|null Decoded API payload, or null on any failure.
     */
    public function fetch(bool $bypassCache = false): ?array
    {
        if (! $bypassCache) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $endpoint = $this->settings->getEndpointBase();
        $siteKey = $this->settings->getSiteKey();
        $apiKey = $this->settings->getAdminApiKey();

        if ($endpoint === '' || $siteKey === '' || $apiKey === '') {
            return null;
        }

        $url = rtrim($endpoint, '/').'/server/keys/'.rawurlencode($siteKey).'?chartDuration=today';

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => [
                'Authorization' => 'Bot '.$apiKey,
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            return null;
        }

        set_transient(self::CACHE_KEY, $body, self::CACHE_TTL);

        return $body;
    }

    public function flush(): void
    {
        delete_transient(self::CACHE_KEY);
    }
}
