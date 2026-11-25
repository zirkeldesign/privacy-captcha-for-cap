<?php

declare(strict_types=1);

/**
 * 1.8.2 fixed a check that ran only when the POST carried `wp-submit`, and was
 * skipped when it carried a WooCommerce nonce field: both are fields the sender
 * chooses, and core handled the request without them. The pattern had been
 * copied into three integrations before anyone noticed.
 *
 * Every request field an integration reads is listed here with the reason it
 * is safe. A new read fails this test until it is added, which is the moment
 * to ask: does the handler behind this hook refuse the request without that
 * field? If it does not, the field must not decide whether the check runs.
 */
const CAP_ALLOWED_REQUEST_FIELDS = [
    // wp-comments-post.php cannot store a comment without the post id.
    "Comments.php => 'comment_post_ID'",
    "WpDiscuz.php => 'comment_post_ID'",
    // WooCommerce verifies these nonces itself before it registers a customer
    // or sends a reset link, so a request without one does nothing.
    "WooCommerce.php => 'woocommerce-register-nonce'",
    "WooCommerce.php => 'woocommerce-lost-password-nonce'",
    // The token itself, and Gravity Forms' own paging fields: they select
    // which page is validated, never whether the last page is.
    "GravityForms/Field.php => 'cap-token'",
    "GravityForms/Validator.php => 'cap-token'",
    'GravityForms/Validator.php => $key',
    "GravityForms/Validator.php => 'gform_field_values'",
    "GravityForms/VerifiedState.php => 'gform_unique_id'",
];

it('reads no request field in an integration that is not on the reviewed list', function (): void {
    $root = dirname(__DIR__, 2).'/src/Integration/';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            '/\$_(?:POST|GET|REQUEST|COOKIE)\[\s*([^\]]+?)\s*\]|RequestContext::is(?:FormPost|PostFrom)\(\s*([^)]+?)\s*\)/',
            (string) file_get_contents($file->getPathname()),
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $field = ($match[1] ?? '') !== '' ? $match[1] : $match[2];
            $found[] = substr($file->getPathname(), strlen($root)).' => '.$field;
        }
    }

    $found = array_values(array_unique($found));

    expect($found)->not->toBeEmpty()
        ->and(array_values(array_diff($found, CAP_ALLOWED_REQUEST_FIELDS)))->toBe([])
        ->and(array_values(array_diff(CAP_ALLOWED_REQUEST_FIELDS, $found)))->toBe([]);
});
