<?php

declare(strict_types=1);

/*
 * The Orbital presets in assets/css/cap-captcha.css map Gravity Forms tokens
 * onto the widget's custom properties. Both sides are upstream names that can
 * change under us without any PHP noticing, so these tests pin them.
 */

function capStylesheetWithoutComments(): string
{
    $css = (string) file_get_contents(dirname(__DIR__, 2).'/assets/css/cap-captcha.css');

    return (string) preg_replace('#/\*.*?\*/#s', '', $css);
}

function capOrbitalPresetBlock(): string
{
    preg_match('/:where\(\.gform-theme--framework\)\s*\{(.*?)\n    \}/s', capStylesheetWithoutComments(), $match);

    return $match[1] ?? '';
}

/**
 * @return array<int, string>
 */
function capPresetNames(string $pattern): array
{
    preg_match_all($pattern, capOrbitalPresetBlock(), $matches);

    return array_values(array_unique($matches[1]));
}

it('declares the presets on the form wrapper', function (): void {
    expect(capOrbitalPresetBlock())->not->toBe('');
});

it('never declares the presets on the widget element itself', function (): void {
    // A declaration on cap-widget beats anything a theme sets on an ancestor,
    // so themes could no longer restyle the widget from the form wrapper.
    expect(capStylesheetWithoutComments())->not->toMatch('/cap-widget[^{]*\{[^}]*--cap-/');
});

it('only sets properties the vendored widget reads', function (): void {
    $widget = (string) file_get_contents(dirname(__DIR__, 2).'/assets/js/vendor/cap-widget.js');
    $names = capPresetNames('/(--cap-[a-z-]+):/');

    expect($names)->not->toBeEmpty();

    foreach ($names as $name) {
        expect(str_contains($widget, "var({$name}"))->toBeTrue("{$name} is not read by the vendored cap-widget");
    }
});

it('only reads tokens gravity forms defines', function (): void {
    $root = getenv('CAP_WP_ROOT') ?: dirname(__DIR__, 2).'/.wp-integration';
    $framework = "{$root}/wp-content/plugins/gravityforms/assets/css/dist/gravity-forms-theme-framework.min.css";

    if (! is_file($framework)) {
        $this->markTestSkipped('Gravity Forms is not installed, run composer test:integration:setup with CAP_GF_SOURCE.');
    }

    $css = (string) file_get_contents($framework);

    foreach (capPresetNames('/var\((--gf-[a-z-]+)/') as $name) {
        expect(str_contains($css, "{$name}:"))->toBeTrue("{$name} is not defined by Gravity Forms");
    }
});
