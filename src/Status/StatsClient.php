<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * Reads the Cap server's key API with the API key from the settings.
 *
 * The `/server/keys/<siteKey>` payload is cached in a 5-minute transient so
 * the settings page doesn't hit the API on every load.
 */
final class StatsClient
{
    public const CACHE_KEY = 'cap_captcha_stats';

    public const CACHE_TTL = 300;

    private ?RequestFailure $failure = null;

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

        $body = $this->read();
        if ($body !== null) {
            set_transient(self::CACHE_KEY, $body, self::CACHE_TTL);
        }

        return $body;
    }

    /**
     * The payload straight from the Cap server, leaving the cache as it is.
     *
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $siteKey = $this->settings->getSiteKey();
        if ($siteKey === '') {
            return null;
        }

        return $this->get('server/keys/'.rawurlencode($siteKey).'?chartDuration=today');
    }

    /**
     * How many site keys the API key can read. An API key that Cap limits to
     * chosen site keys lists only those; an unrestricted one lists them all.
     *
     * @return int|null Null when the Cap server did not answer with a list.
     */
    public function siteKeyCount(): ?int
    {
        $keys = $this->get('server/keys');

        return $keys !== null && array_is_list($keys) ? count($keys) : null;
    }

    /**
     * Why the last request failed, or null when it went through or was never
     * sent for lack of an endpoint or API key.
     */
    public function failure(): ?RequestFailure
    {
        return $this->failure;
    }

    public function flush(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * @return array<mixed>|null
     */
    private function get(string $path): ?array
    {
        $this->failure = null;

        $endpoint = $this->settings->getEndpointBase();
        $apiKey = $this->settings->getAdminApiKey();

        if ($endpoint === '' || $apiKey === '') {
            return null;
        }

        $response = wp_remote_get($endpoint.$path, [
            'timeout' => 5,
            'headers' => [
                'Authorization' => 'Bot '.$apiKey,
                'Accept' => 'application/json',
            ],
        ]);

        $failure = RequestFailure::fromResponse($response);
        if ($failure->status === null || $failure->status < 200 || $failure->status >= 300) {
            $this->failure = $failure;

            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            $this->failure = $failure;

            return null;
        }

        return $body;
    }
}
