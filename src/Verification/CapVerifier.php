<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Verification;

final class CapVerifier
{
    public function __construct(
        private readonly string $endpointBase,
        private readonly string $siteKey,
        private readonly string $secretKey,
        private readonly int $timeout = 5,
    ) {}

    /** A Cap token is under 100 characters. Anything far beyond is not one. */
    private const MAX_TOKEN_LENGTH = 512;

    public function verify(string $token): bool
    {
        return $this->check($token) === VerificationResult::Verified;
    }

    /**
     * Verify a token, distinguishing an active rejection from an unreachable
     * server. An empty token or missing configuration is reported as
     * Unreachable (we cannot verify), so the caller's fail-open policy decides.
     * A request the sender can cause to fail (oversized token, a 4xx from a
     * proxy in front of Cap) is a rejection, never a reason to fail open.
     */
    public function check(string $token): VerificationResult
    {
        $token = trim($token);

        if ($token === '' || $this->endpointBase === '' || $this->siteKey === '' || $this->secretKey === '') {
            return VerificationResult::Unreachable;
        }

        if (strlen($token) > self::MAX_TOKEN_LENGTH) {
            return VerificationResult::Rejected;
        }

        $url = rtrim($this->endpointBase, '/').'/'.$this->siteKey.'/siteverify';

        $response = wp_remote_post(
            $url,
            [
                'timeout' => $this->timeout,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(['secret' => $this->secretKey, 'response' => $token]),
            ]
        );

        if (is_wp_error($response)) {
            return VerificationResult::Unreachable;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($body)) {
            // A 4xx without JSON is a proxy refusing this request, not an outage,
            // so it must not open the form. Timeouts and rate limits are outages.
            $status = (int) wp_remote_retrieve_response_code($response);

            return $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true)
                ? VerificationResult::Rejected
                : VerificationResult::Unreachable;
        }

        return ($body['success'] ?? false) === true
            ? VerificationResult::Verified
            : VerificationResult::Rejected;
    }
}
