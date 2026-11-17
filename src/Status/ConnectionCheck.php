<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

use ZirkelDesign\CapCaptcha\Settings;

/**
 * The "Test connection" button of the settings page.
 *
 * It runs on the settings it is given, which the button fills from the form
 * as it stands, saved or not.
 *
 * Forms depend on three things, checked in this order: the Cap server accepts
 * the site key and its secret, the key's rules are ones the plugin's settings
 * can satisfy, and the widget can load the solver the key's protocol needs.
 * The first needs no API key. The other two read the key through the API, so
 * without an API key the check passes on the secret alone and says what it
 * could not look at.
 *
 * A solver the widget cannot load has no fallback: when the WASM source is
 * the Cap server and that server does not serve the file, every protected
 * form is unsubmittable while siteverify still answers normally.
 */
final class ConnectionCheck
{
    public function __construct(
        private readonly Settings $settings,
        private readonly StatsClient $stats,
        private readonly SolverProbe $solvers,
        private readonly SecretProbe $secret,
    ) {}

    public function run(): ConnectionCheckResult
    {
        if (! $this->settings->isConfigured()) {
            return ConnectionCheckResult::failed(__('Endpoint / Site key / Secret key not yet configured.', 'privacy-captcha-for-cap'));
        }

        $refused = $this->secret->failure(
            $this->settings->getEndpointBase(),
            $this->settings->getSiteKey(),
            $this->settings->getSecretKey(),
        );

        if ($refused !== null) {
            return ConnectionCheckResult::failed($this->secretRefused($refused));
        }

        return $this->settings->getAdminApiKey() === ''
            ? $this->withoutApiKey()
            : $this->withApiKey();
    }

    private function secretRefused(RequestFailure $failure): string
    {
        if ($failure->status === null) {
            return __('The Cap server did not answer. Check the endpoint URL.', 'privacy-captcha-for-cap');
        }

        if ($failure->serverMessage === '') {
            return $this->notCap($failure);
        }

        return sprintf(
            /* translators: %s: what the Cap server answered, e.g. "HTTP 403: Invalid site key or secret" */
            __('The Cap server refused the site key or secret key (%s).', 'privacy-captcha-for-cap'),
            $failure->describe()
        );
    }

    /**
     * An answer that carries no Cap error: a proxy page, another website.
     */
    private function notCap(RequestFailure $failure): string
    {
        return sprintf(
            /* translators: %s: "HTTP 200" */
            __('The endpoint did not answer like a Cap server (%s). Check the endpoint URL.', 'privacy-captcha-for-cap'),
            $failure->describe()
        );
    }

    private function withoutApiKey(): ConnectionCheckResult
    {
        $connected = __('Connected — the Cap server accepts the site key and secret key', 'privacy-captcha-for-cap');
        $warnings = [__('No API key: the status panel and the dashboard statistics stay off.', 'privacy-captcha-for-cap')];

        if (! $this->settings->isWasmFromCapServer()) {
            return ConnectionCheckResult::ok($connected, $warnings);
        }

        // The protocol of the key decides which solver the widget loads, and
        // only the API tells it. Either file may be the one that matters.
        $missing = [];
        foreach ([KeyProtocol::HASHWX, KeyProtocol::SHA256] as $name) {
            $protocol = KeyProtocol::fromConfig(['protocol' => $name]);
            $status = $this->solvers->status($this->solverUrl($protocol));
            if ($status !== 200) {
                $missing[] = [$protocol, $status];
            }
        }

        if (count($missing) === 2) {
            return ConnectionCheckResult::failed($this->solverMissing($connected, ...$missing[0]));
        }

        foreach ($missing as [$protocol, $status]) {
            $warnings[] = sprintf(
                /* translators: 1: solver file name, 2: "HTTP 503" or "no response", 3: challenge protocol */
                __('The Cap server does not serve %1$s (%2$s). Forms fail if the site key uses the %3$s protocol.', 'privacy-captcha-for-cap'),
                $protocol->solverFile(),
                (new RequestFailure($status))->describe(),
                $protocol->name
            );
        }

        return ConnectionCheckResult::ok($connected, $warnings);
    }

    private function withApiKey(): ConnectionCheckResult
    {
        $stats = $this->stats->read();

        if ($stats === null) {
            $failure = $this->stats->failure() ?? new RequestFailure(null);

            if ($failure->status !== null && $failure->status < 300 && $failure->serverMessage === '') {
                return ConnectionCheckResult::failed($this->notCap($failure));
            }

            return ConnectionCheckResult::failed(sprintf(
                /* translators: %s: what the Cap server answered, e.g. "HTTP 401: Unauthorized. Invalid bot token." */
                __('The Cap server accepts the site key and secret key, but not the API key (%s).', 'privacy-captcha-for-cap'),
                $failure->describe()
            ));
        }

        $name = (string) ($stats['key']['name'] ?? '');
        $siteKey = (string) ($stats['key']['siteKey'] ?? '');
        $config = is_array($stats['key']['config'] ?? null) ? $stats['key']['config'] : [];
        $protocol = KeyProtocol::fromConfig($config);
        $policy = KeyPolicy::fromConfig($config);

        $connected = sprintf(
            /* translators: 1: site name, 2: site key, 3: challenge protocol, e.g. "hashwx" */
            __('Connected — site "%1$s" (%2$s), %3$s key', 'privacy-captcha-for-cap'),
            $name !== '' ? $name : '—',
            $siteKey,
            $protocol->name
        );

        if ($policy->rejectsIsolatedFrames && $this->settings->isFrameIsolated()) {
            return ConnectionCheckResult::failed(sprintf(
                /* translators: %s: the connection message */
                __('%s, but the key rejects an isolated check: it blocks on behavior or on automated interactions. Turn "Isolate the Cap check from the page" off, or relax the key in the Cap dashboard.', 'privacy-captcha-for-cap'),
                $connected
            ));
        }

        if ($this->settings->isWasmFromCapServer()) {
            $status = $this->solvers->status($this->solverUrl($protocol));

            if ($status !== 200) {
                return ConnectionCheckResult::failed($this->solverMissing($connected, $protocol, $status));
            }

            $connected = sprintf(
                /* translators: 1: the connection message, 2: solver file name, e.g. "hashwx.wasm" */
                __('%1$s · %2$s served by the Cap server', 'privacy-captcha-for-cap'),
                $connected,
                $protocol->solverFile()
            );
        }

        $warnings = [];

        if ($policy->requiresInteraction && $this->settings->getDisplayMode() !== Settings::MODE_INLINE) {
            $warnings[] = __('The key requires a press on the widget, which the floating and programmatic display modes never give: forms in those modes fail. Use the inline mode.', 'privacy-captcha-for-cap');
        }

        $readable = $this->stats->siteKeyCount();
        if ($readable !== null && $readable > 1) {
            $warnings[] = sprintf(
                /* translators: %d: number of site keys */
                __('The API key can read %d site keys. Create one that is read-only and limited to this site key.', 'privacy-captcha-for-cap'),
                $readable
            );
        }

        return ConnectionCheckResult::ok($connected, $warnings);
    }

    private function solverUrl(KeyProtocol $protocol): string
    {
        return $protocol->isHashwx()
            ? $this->settings->getSelfHostedHashwxUrl()
            : $this->settings->getSelfHostedWasmUrl();
    }

    private function solverMissing(string $connected, KeyProtocol $protocol, ?int $status): string
    {
        return sprintf(
            /* translators: 1: the connection message, 2: solver file name, 3: "HTTP 503" or "no response", 4: challenge protocol */
            __('%1$s, but the Cap server does not serve %2$s (%3$s). The widget cannot solve a %4$s challenge without it: turn on Widget files in the Cap dashboard (ENABLE_ASSETS_SERVER before Cap 4) with a WASM version that ships the file, or switch the WASM source to Bundled.', 'privacy-captcha-for-cap'),
            $connected,
            $protocol->solverFile(),
            (new RequestFailure($status))->describe(),
            $protocol->name
        );
    }
}
