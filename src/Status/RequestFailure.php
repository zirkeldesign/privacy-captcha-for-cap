<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

/**
 * Why a request to the Cap server did not give the expected answer: the HTTP
 * status it answered with, if it answered at all, and the `error` text of its
 * JSON body. Cap words that text for the person running the site ("This API
 * key was made by an older Cap release…"), so it is shown as is, cut to a
 * length no Cap message reaches: the endpoint is whatever URL was typed in.
 */
final readonly class RequestFailure
{
    private const MESSAGE_LENGTH = 300;

    public function __construct(
        public ?int $status,
        public string $serverMessage = '',
    ) {}

    /**
     * @param  array<string, mixed>|\WP_Error  $response  What wp_remote_request() returned.
     */
    public static function fromResponse(array|\WP_Error $response): self
    {
        if (is_wp_error($response)) {
            return new self(null);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $message = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : '';

        return new self($status > 0 ? $status : null, mb_substr($message, 0, self::MESSAGE_LENGTH));
    }

    public function describe(): string
    {
        if ($this->status === null) {
            return __('no response', 'privacy-captcha-for-cap');
        }

        /* translators: %d: HTTP status code */
        $status = sprintf(__('HTTP %d', 'privacy-captcha-for-cap'), $this->status);

        return $this->serverMessage === '' ? $status : $status.': '.$this->serverMessage;
    }
}
