<?php

/**
 * Extract the plugin version from the main file header for build scripts.
 *
 * Usage: `php get-version.php` → "1.0.0"
 *
 * The dist composer script writes this to a temp `.version` file so the zip
 * name can include it without invoking PHP twice.
 */
$content = file_get_contents(__DIR__.'/privacy-captcha-for-cap.php');

if ($content === false) {
    fwrite(STDERR, "Could not read privacy-captcha-for-cap.php\n");
    exit(1);
}

if (preg_match('/^\s*\*\s*Version:\s*([0-9A-Za-z.+-]+)/m', $content, $matches)) {
    echo $matches[1];
    exit(0);
}

echo '0.0.0';
