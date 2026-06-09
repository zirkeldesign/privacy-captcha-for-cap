<?php

declare(strict_types=1);

namespace ZirkelDesign\GFCapCaptcha\Validation;

use Closure;
use ZirkelDesign\GFCapCaptcha\Settings;
use ZirkelDesign\GFCapCaptcha\Verification\CapVerifier;

final class SubmissionValidator
{
    /**
     * @param  Closure(): Settings  $settingsResolver
     * @param  (Closure(string, Settings): bool)|null  $verifyOverride  Test seam.
     */
    public function __construct(
        private readonly Closure $settingsResolver,
        private readonly ?Closure $verifyOverride = null,
    ) {}

    /**
     * @param  array{is_valid: bool, form: array<string, mixed>}  $result
     * @return array{is_valid: bool, form: array<string, mixed>}
     */
    public function validate(array $result): array
    {
        $form = $result['form'];

        if (empty($form['fields']) || ! is_array($form['fields'])) {
            return $result;
        }

        $field = $this->findCapField($form['fields']);

        if ($field === null) {
            return $result;
        }

        $token = isset($_POST['cap-token']) && is_string($_POST['cap-token'])
            ? trim($_POST['cap-token'])
            : '';

        if ($token === '') {
            return $this->failResult($result, $field, esc_html__('Please complete the CAPTCHA before submitting.', 'cap-captcha-for-gravity-forms'));
        }

        $settings = ($this->settingsResolver)();
        $verified = $this->verifyOverride !== null
            ? ($this->verifyOverride)($token, $settings)
            : (new CapVerifier($settings->getEndpointBase(), $settings->getSiteKey(), $settings->getSecretKey()))->verify($token);

        if (! $verified) {
            return $this->failResult($result, $field, esc_html__('CAPTCHA verification failed. Please try again.', 'cap-captcha-for-gravity-forms'));
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $fields
     */
    private function findCapField(array $fields): ?object
    {
        foreach ($fields as $field) {
            if (! is_object($field)) {
                continue;
            }
            if (isset($field->type) && $field->type === 'cap_captcha') {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array{is_valid: bool, form: array<string, mixed>}  $result
     * @return array{is_valid: bool, form: array<string, mixed>}
     */
    private function failResult(array $result, object $field, string $message): array
    {
        $result['is_valid'] = false;

        $field->failed_validation = true;
        $field->validation_message = $message;

        return $result;
    }
}
