<?php

declare(strict_types=1);

namespace ZirkelDesign\CapCaptcha\Status;

/**
 * What a site key demands of the widget beyond the proof of work, read from
 * the `config` object of the key.
 *
 * Cap 4 can score how the visitor reached the widget and count scripted
 * clicks. Both need the instrumentation script to read the page, and scoring
 * can also demand a real press on the widget, so two plugin settings can
 * leave a key unsolvable: an isolated instrumentation frame, and a display
 * mode that solves without a click.
 */
final readonly class KeyPolicy
{
    /**
     * Risk flags whose "block" action turns an isolated frame away: the two
     * interaction counts an isolated frame cannot take, and the flag Cap puts
     * on every isolated solve.
     */
    private const ISOLATION_SIGNALS = [
        'interaction_synthetic_clicks',
        'interaction_synthetic_keydowns',
        'isolated_frame',
    ];

    private function __construct(
        public bool $rejectsIsolatedFrames,
        public bool $requiresInteraction,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        // A key with instrumentation off sends no script, whatever rules are
        // left over in its config.
        if (($config['instrumentation'] ?? true) === false) {
            return new self(false, false);
        }

        $blocksBehavior = ($config['behavior'] ?? 'off') === 'block';
        $signals = is_array($config['signalActions'] ?? null) ? $config['signalActions'] : [];
        $blocksSignal = array_intersect_key($signals, array_flip(self::ISOLATION_SIGNALS));

        return new self(
            $blocksBehavior
                || ! empty($config['blockAutomatedInteractions'])
                || in_array('block', $blocksSignal, true),
            $blocksBehavior && ! empty($config['requireInteraction']),
        );
    }
}
