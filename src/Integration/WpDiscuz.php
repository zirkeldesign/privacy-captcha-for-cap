<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Integration;

use ZirkelDesign\CapCaptcha\Asset\Enqueuer;
use ZirkelDesign\CapCaptcha\Asset\Renderer;
use ZirkelDesign\CapCaptcha\Settings;
use ZirkelDesign\CapCaptcha\Verification\RequestContext;
use ZirkelDesign\CapCaptcha\Verification\TokenVerifier;

/**
 * wpDiscuz replaces the theme's comment form with its own AJAX forms. Reply
 * forms are cloned in the browser from one hidden template whose unique id is
 * the placeholder "wpdiscuzuniqueid", so the widget id carries the unique id
 * and every clone gets its own widget.
 */
final class WpDiscuz implements Integration
{
    public const PHRASE_KEY = 'cap_captcha_wpdiscuz_failed';

    private bool $lastFailOpen = false;

    private bool $buffering = false;

    private string $floatingWidgetId = '';

    public function __construct(
        private readonly Settings $settings,
        private readonly Renderer $renderer,
        private readonly Enqueuer $enqueuer,
        private readonly TokenVerifier $verifier,
    ) {}

    public function id(): string
    {
        return 'wpdiscuz';
    }

    public function isAvailable(): bool
    {
        return defined('WPDISCUZ_DIR_PATH');
    }

    public function register(): void
    {
        add_action('wpdiscuz_submit_button_before', [$this, 'renderWidget'], 10, 3);
        add_action('comment_form', [$this, 'wireSubmitButton'], 1, 0);
        add_filter('wpdiscuz_js_options', [$this, 'addPhrase']);
        add_action('wpdiscuz_before_wp_new_comment', [$this, 'verifyComment']);
        add_filter('preprocess_comment', [$this, 'verifyDirectPost']);
        add_action('comment_post', [$this, 'annotateFailOpen']);
    }

    /**
     * Runs inside each wpDiscuz form, right before its submit button.
     */
    public function renderWidget(mixed $currentUser = null, mixed $uniqueId = '', mixed $isMainForm = true): void
    {
        if (! $this->settings->isProtected($this->id())) {
            return;
        }

        if (! $this->settings->isConfigured()) {
            echo wp_kses($this->renderer->renderAdminNotice('wpDiscuz'), Renderer::ADMIN_NOTICE_KSES);

            return;
        }

        $mode = $this->settings->getDisplayMode();
        // The shared programmatic module writes one token into every field; each wpDiscuz form needs its own.
        $mode === Settings::MODE_PROGRAMMATIC ? $this->enqueuer->enqueueCore() : $this->enqueuer->enqueueForMode($mode);
        wp_enqueue_script(
            'cap-captcha-wpdiscuz',
            CAP_CAPTCHA_URL.'assets/js/wpdiscuz.js',
            ['jquery'],
            CAP_CAPTCHA_VERSION,
            ['in_footer' => true]
        );
        $this->enqueuer->printGlobals();

        $widgetId = 'cap-captcha-wpdiscuz-'.(is_string($uniqueId) ? $uniqueId : '');
        $widget = $this->renderer->renderForMode($widgetId, $mode, false);
        $error = esc_html__('CAPTCHA could not be started. Please try again.', 'privacy-captcha-for-cap');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the widget attributes and text.
        echo '<div class="cap-captcha--wpdiscuz">'.$widget.'<div class="cap-captcha-wpdiscuz-error" role="alert" hidden>'.$error.'</div></div>';

        if ($mode === Settings::MODE_FLOATING) {
            // wpDiscuz has no filter on its submit button; capture it until comment_form closes the form.
            $this->floatingWidgetId = $widgetId;
            $this->buffering = ob_start();
        }
    }

    /**
     * In floating mode the form's own submit button opens the check, as in
     * the core comment form.
     */
    public function wireSubmitButton(): void
    {
        if (! $this->buffering) {
            return;
        }

        $this->buffering = false;
        $html = (string) ob_get_clean();

        $wired = (string) preg_replace_callback(
            '/<input\b[^>]*\bwc_comm_submit\b[^>]*>/',
            fn (array $match): string => Renderer::addFloatingAttrsToSubmit($match[0], $this->floatingWidgetId),
            $html,
            1
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpDiscuz' own form markup, already escaped by wpDiscuz; only data attributes escaped via esc_attr() are added.
        echo $wired;
    }

    /**
     * wpDiscuz shows an AJAX error by looking its phrase key up in the
     * options it hands to its script.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function addPhrase(array $options): array
    {
        $options[self::PHRASE_KEY] = __('CAPTCHA verification failed. Please try again.', 'privacy-captcha-for-cap');

        return $options;
    }

    /**
     * Fires in wpDiscuz' AJAX handler right before the comment is inserted,
     * for the main form and every reply form.
     */
    public function verifyComment(): void
    {
        if (! $this->settings->isProtected($this->id())) {
            return;
        }

        if (! $this->verifier->verifyCurrentRequest($this->id())) {
            wp_send_json_error(self::PHRASE_KEY);
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();
    }

    /**
     * wpDiscuz hides the core comment form, but wp-comments-post.php still
     * accepts posts, and that is where comment bots send them. wpDiscuz' own
     * CAPTCHA guards that path for posts that use a wpDiscuz form; this does
     * the same unless the Comments integration already does. Posts without a
     * wpDiscuz form show the core form, which carries no widget then.
     *
     * @param  array<string, mixed>  $commentData
     * @return array<string, mixed>
     */
    public function verifyDirectPost(array $commentData): array
    {
        if (! $this->settings->isProtected($this->id()) || $this->settings->isProtected('comments')) {
            return $commentData;
        }

        if (! RequestContext::isFormPost('comment_post_ID')) {
            return $commentData;
        }

        $type = $commentData['comment_type'] ?? '';
        if (in_array($type, ['pingback', 'trackback'], true)) {
            return $commentData;
        }

        $postId = $commentData['comment_post_ID'] ?? 0;
        if (! $this->hasWpDiscuzForm(is_numeric($postId) ? (int) $postId : 0)) {
            return $commentData;
        }

        if (! $this->verifier->verifyCurrentRequest($this->id())) {
            wp_die(
                esc_html__('CAPTCHA verification failed. Please go back and complete the challenge.', 'privacy-captcha-for-cap'),
                esc_html__('CAPTCHA verification failed', 'privacy-captcha-for-cap'),
                ['response' => 403, 'back_link' => true]
            );
        }

        $this->lastFailOpen = $this->verifier->wasLastFailOpen();

        return $commentData;
    }

    private function hasWpDiscuzForm(int $postId): bool
    {
        if ($postId <= 0 || ! function_exists('wpDiscuz')) {
            return false;
        }

        $forms = wpDiscuz()->wpdiscuzForm ?? null;

        return is_object($forms) && method_exists($forms, 'getForm') && (bool) $forms->getForm($postId)->getFormID();
    }

    /**
     * Tag a comment that was accepted only because Cap was unreachable.
     *
     * @param  int|string  $commentId
     */
    public function annotateFailOpen($commentId): void
    {
        if (! $this->lastFailOpen) {
            return;
        }

        $this->lastFailOpen = false;
        add_comment_meta((int) $commentId, 'cap_captcha_fail_open', 1, true);
        do_action('cap_captcha_fail_open_pass', $this->id(), ['comment_id' => (int) $commentId]);
    }
}
