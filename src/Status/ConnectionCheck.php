<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * The "Test connection" button of the settings page.
 *
 * Reads the site key through the admin API, which proves the endpoint, the
 * site key and the admin API key at once, and reports the key's protocol.
 * When the WASM source is the Cap server, it then fetches the solver file
 * that protocol needs from the server's /assets/ route: the widget has no
 * fallback for a solver it cannot load, so a server that does not serve the
 * file leaves every protected form unsubmittable while siteverify still
 * answers normally.
 */
final class ConnectionCheck
{
    public function __construct(
        private readonly Settings $settings,
        private readonly StatsClient $stats,
        private readonly SolverProbe $probe,
    ) {}

    public function run(): ConnectionCheckResult
    {
        if (! $this->settings->isConfigured()) {
            return ConnectionCheckResult::failed(__('Endpoint / Site key / Secret key not yet configured.', 'privacy-captcha-for-cap'));
        }

        if ($this->settings->getAdminApiKey() === '') {
            return ConnectionCheckResult::failed(__('Admin API key is empty — connection test needs it.', 'privacy-captcha-for-cap'));
        }

        $stats = $this->stats->fetch(true);

        if ($stats === null) {
            return ConnectionCheckResult::failed(__('Cap server did not return a valid response. Verify endpoint and admin API key.', 'privacy-captcha-for-cap'));
        }

        $name = (string) ($stats['key']['name'] ?? '');
        $siteKey = (string) ($stats['key']['siteKey'] ?? '');
        $config = is_array($stats['key']['config'] ?? null) ? $stats['key']['config'] : [];
        $protocol = KeyProtocol::fromConfig($config);

        $connected = sprintf(
            /* translators: 1: site name, 2: site key, 3: challenge protocol, e.g. "hashwx" */
            __('Connected — site "%1$s" (%2$s), %3$s key', 'privacy-captcha-for-cap'),
            $name !== '' ? $name : '—',
            $siteKey,
            $protocol->name
        );

        if (! $this->settings->isWasmFromCapServer()) {
            return ConnectionCheckResult::ok($connected);
        }

        $file = $protocol->solverFile();
        $url = $protocol->isHashwx()
            ? $this->settings->getSelfHostedHashwxUrl()
            : $this->settings->getSelfHostedWasmUrl();
        $status = $this->probe->status($url);

        if ($status === 200) {
            return ConnectionCheckResult::ok(sprintf(
                /* translators: 1: the connection message, 2: solver file name, e.g. "hashwx.wasm" */
                __('%1$s · %2$s served by the Cap server', 'privacy-captcha-for-cap'),
                $connected,
                $file
            ));
        }

        return ConnectionCheckResult::failed(sprintf(
            /* translators: 1: the connection message, 2: solver file name, 3: "HTTP 503" or "no response", 4: challenge protocol */
            __('%1$s, but the Cap server does not serve %2$s (%3$s). The widget cannot solve a %4$s challenge without it: enable the asset server on the Cap server with a WASM_VERSION that ships the file, or switch the WASM source to Bundled.', 'privacy-captcha-for-cap'),
            $connected,
            $file,
            $status === null
                ? __('no response', 'privacy-captcha-for-cap')
                /* translators: %d: HTTP status code */
                : sprintf(__('HTTP %d', 'privacy-captcha-for-cap'), $status),
            $protocol->name
        ));
    }
}
