<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

/**
 * Checks the site key and its secret against the Cap server without solving a
 * challenge.
 *
 * /siteverify authenticates the secret before it looks the token up, so a
 * token that was never issued tells both cases apart: "Token not found" once
 * the secret is accepted, a 403 or 404 on the key itself otherwise. Cap 4
 * records the request in the event log of the key, as a failed siteverify.
 */
final class SecretProbe
{
    private const ACCEPTED = 'Token not found';

    public function __construct(private readonly int $timeout = 5) {}

    /**
     * @return RequestFailure|null Null when the Cap server accepts the secret.
     */
    public function failure(string $endpointBase, string $siteKey, string $secretKey): ?RequestFailure
    {
        $response = wp_remote_post(
            rtrim($endpointBase, '/').'/'.rawurlencode($siteKey).'/siteverify',
            [
                'timeout' => $this->timeout,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'secret' => $secretKey,
                    'response' => $siteKey.':connection-test:connection-test',
                ]),
            ]
        );

        $failure = RequestFailure::fromResponse($response);

        return $failure->serverMessage === self::ACCEPTED ? null : $failure;
    }
}
