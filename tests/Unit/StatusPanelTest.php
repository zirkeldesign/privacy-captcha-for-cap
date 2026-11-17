<?php

declare(strict_types=1);

use ZirkelDesign\CapCaptcha\Status\RequestFailure;
use ZirkelDesign\CapCaptcha\Status\StatusPanel;

it('says why the Cap server gave no stats when it answered', function (): void {
    $line = StatusPanel::unavailable(new RequestFailure(401, 'Unauthorized. Invalid bot token.'));

    expect($line)->toBe('Could not fetch status from the Cap server (HTTP 401: Unauthorized. Invalid bot token.). Check the endpoint, site key and API key.');
});

it('keeps to the general advice when no request was sent', function (): void {
    expect(StatusPanel::unavailable(null))->toBe('Could not fetch status from the Cap server. Check the endpoint, site key and API key.');
});
