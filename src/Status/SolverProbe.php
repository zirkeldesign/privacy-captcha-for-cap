<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

/**
 * Asks a URL for a solver file and reports the HTTP status it answers with.
 *
 * Cap Standalone serves the widget's WebAssembly modules under /assets/ only
 * when ENABLE_ASSETS_SERVER is on (404 otherwise), and answers 503 for a file
 * its pinned WASM_VERSION does not contain, which is the case of hashwx.wasm
 * below @cap.js/wasm 0.0.8. A GET rather than a HEAD, since the asset routes
 * are plain GET handlers and the files are a few dozen kilobytes.
 */
final class SolverProbe
{
    public function __construct(private readonly int $timeout = 5) {}

    /**
     * @return int|null The HTTP status, or null when no response came back.
     */
    public function status(string $url): ?int
    {
        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/wasm'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        return $status > 0 ? $status : null;
    }
}
