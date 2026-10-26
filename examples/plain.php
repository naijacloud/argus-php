<?php

declare(strict_types=1);

/*
 * Plain PHP: run with
 *   ARGUS_DSN=https://<key>@argus-in.naijacloud.com/<id> php examples/plain.php
 */

use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Span;

require __DIR__ . '/../vendor/autoload.php';

\NaijaCloud\Argus\init(['release' => 'example@1.0.0', 'debug' => true]);

Argus::setUser(['id' => 'user-81']);
Argus::logs()->info('example started');

Argus::trace('process order', static function (Span $span): void {
    $span->setAttribute('order.id', 'ord_81');
    Argus::metrics()->count('orders.processed');
    try {
        throw new RuntimeException('card declined');
    } catch (RuntimeException $e) {
        Argus::captureException($e, ['tags' => ['provider' => 'paystack']]);
    }
});

Argus::cron()->withMonitor('example-job', static fn () => usleep(100_000));

echo Argus::flags()->isOn('new-checkout', 'user-81') ? "new checkout\n" : "old checkout\n";
// Everything queued is sent at shutdown; flush() is only needed to see the result.
var_dump(Argus::flush());
