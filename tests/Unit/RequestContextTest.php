<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Verification\RequestContext;

/**
 * isNonInteractive() inspects the REST_REQUEST / XMLRPC_REQUEST / WP_CLI
 * constants and wp_doing_cron(), none of which exist in the Pest process. A
 * constant set in one test cannot be undone for the next — it would make every
 * later request look non-interactive — so the "true" branches are exercised in
 * a throw-away interpreter that dies with the test, via capRunRequestContext().
 */

/**
 * Run a snippet in a fresh PHP process so it can define the runner constants
 * without poisoning the shared suite. $method is the static method to evaluate;
 * $args are its pre-rendered argument literals.
 */
function capRunRequestContext(string $prelude, string $method, array $args = []): bool
{
    $source = dirname(__DIR__, 2).'/src/Verification/RequestContext.php';

    $snippet = "<?php\n";
    $snippet .= 'declare(strict_types=1);'.PHP_EOL;
    $snippet .= $prelude;
    if (! str_ends_with($prelude, "\n")) {
        $snippet .= "\n";
    }
    $snippet .= 'require '.var_export($source, true).';'.PHP_EOL;
    $snippet .= 'echo (\ZirkelDesign\CapCaptcha\Verification\RequestContext::'.$method.'('.implode(', ', $args).')) ? "true" : "false";';

    $tmp = tempnam(sys_get_temp_dir(), 'cap-rc-');
    file_put_contents($tmp, $snippet);

    try {
        $output = shell_exec(escapeshellarg(PHP_BINARY).' -d display_errors=0 '.escapeshellarg($tmp));
    } finally {
        @unlink($tmp);
    }

    return trim((string) $output) === 'true';
}

/**
 * Whether we can spawn the throw-away interpreter. Some sandboxed environments
 * disable shell_exec, in which case the runner-constant cases skip rather than
 * assert a stale result.
 */
function capCanSpawnProcess(): bool
{
    return function_exists('shell_exec') && PHP_BINARY !== '';
}

beforeEach(function (): void {
    $_POST = [];
});

it('detects the marker field when the form we render is posting', function (): void {
    $_POST['wp-submit'] = 'Log In';

    expect(RequestContext::isPostFrom('wp-submit'))->toBeTrue();
});

it('returns false when a marker field is absent', function (): void {
    expect(RequestContext::isPostFrom('wp-submit'))->toBeFalse();
});

it('matches only the exact marker, not a differently named field', function (): void {
    $_POST['comment_post'] = 7;

    expect(RequestContext::isPostFrom('comment_post'))->toBeTrue();
    expect(RequestContext::isPostFrom('wp-submit'))->toBeFalse();
});

it('counts the marker even when its value is empty, mirroring isset()', function (): void {
    $_POST['wp-submit'] = '';

    expect(RequestContext::isPostFrom('wp-submit'))->toBeTrue();
});

it('counts the marker as absent when its value is null, like isset() does', function (): void {
    // isset() is true for an empty-string value but false for a null one; this
    // pins that isPostFrom() tracks isset() exactly rather than key_exists().
    $_POST['wp-submit'] = null;

    expect(RequestContext::isPostFrom('wp-submit'))->toBeFalse();
});

it('treats an ordinary web request as interactive', function (): void {
    // None of the runner constants nor wp_doing_cron() exist in the suite, so a
    // plain browser request is the default.
    expect(RequestContext::isNonInteractive())->toBeFalse();
});

it('reports a REST request as non-interactive', function (): void {
    if (! capCanSpawnProcess()) {
        $this->markTestSkipped('Cannot spawn a child PHP process in this environment.');
    }

    expect(capRunRequestContext("define('REST_REQUEST', true);", 'isNonInteractive'))->toBeTrue();
});

it('reports an XML-RPC request as non-interactive', function (): void {
    if (! capCanSpawnProcess()) {
        $this->markTestSkipped('Cannot spawn a child PHP process in this environment.');
    }

    expect(capRunRequestContext("define('XMLRPC_REQUEST', true);", 'isNonInteractive'))->toBeTrue();
});

it('reports a WP-CLI run as non-interactive', function (): void {
    if (! capCanSpawnProcess()) {
        $this->markTestSkipped('Cannot spawn a child PHP process in this environment.');
    }

    expect(capRunRequestContext("define('WP_CLI', true);", 'isNonInteractive'))->toBeTrue();
});

it('reports a cron job as non-interactive', function (): void {
    if (! capCanSpawnProcess()) {
        $this->markTestSkipped('Cannot spawn a child PHP process in this environment.');
    }

    expect(capRunRequestContext('function wp_doing_cron(): bool { return true; }', 'isNonInteractive'))->toBeTrue();
});

it('does not treat a defined-but-false runner constant as non-interactive', function (): void {
    if (! capCanSpawnProcess()) {
        $this->markTestSkipped('Cannot spawn a child PHP process in this environment.');
    }

    // REST_REQUEST is defined but falsy and no other runner is active, so the
    // `defined() && \CONST` guard must fall through.
    expect(capRunRequestContext("define('REST_REQUEST', false);", 'isNonInteractive'))->toBeFalse();
});

it('reports a marker field as a form post for an interactive request', function (): void {
    $_POST['wp-submit'] = 'Log In';

    expect(RequestContext::isFormPost('wp-submit'))->toBeTrue();
});

it('rejects a form post when the marker is absent, even while interactive', function (): void {
    expect(RequestContext::isFormPost('wp-submit'))->toBeFalse();
});

it('rejects a form post from a non-interactive request even when the marker is present', function (): void {
    if (! capCanSpawnProcess()) {
        $this->markTestSkipped('Cannot spawn a child PHP process in this environment.');
    }

    // REST_REQUEST short-circuits isNonInteractive() to true, so isFormPost()
    // must return false even though the marker field is set.
    $prelude = "define('REST_REQUEST', true);\n\$_POST['wp-submit'] = 'Log In';";

    expect(capRunRequestContext($prelude, 'isFormPost', ["'wp-submit'"]))->toBeFalse();
});

describe('isLoginScreenPost', function (): void {
    beforeEach(function (): void {
        unset($GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD']);
    });

    afterEach(function (): void {
        unset($GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD']);
    });

    it('reports a POST handled by wp-login.php, with or without its submit button', function (): void {
        $GLOBALS['pagenow'] = 'wp-login.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        expect(RequestContext::isLoginScreenPost())->toBeTrue();
    });

    it('ignores a GET of wp-login.php', function (): void {
        $GLOBALS['pagenow'] = 'wp-login.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        expect(RequestContext::isLoginScreenPost())->toBeFalse();
    });

    it('ignores a POST to another page, even one naming its button wp-submit', function (): void {
        $GLOBALS['pagenow'] = 'index.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['wp-submit'] = 'Log In';

        expect(RequestContext::isLoginScreenPost())->toBeFalse();
    });
});
