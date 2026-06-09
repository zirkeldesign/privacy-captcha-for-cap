<?php

declare(strict_types=1);

namespace ZirkelDesign\GFCapCaptcha\Verification;

final class CapVerifier
{
    public function __construct(
        private readonly string $endpointBase,
        private readonly string $siteKey,
        private readonly string $secretKey,
        private readonly int $timeout = 5,
    ) {}

    public function verify(string $token): bool
    {
        $token = trim($token);

        if ($token === '' || $this->endpointBase === '' || $this->siteKey === '' || $this->secretKey === '') {
            return false;
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
            return false;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) && ($body['success'] ?? false) === true;
    }
}
