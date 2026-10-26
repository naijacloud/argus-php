<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Log;

use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Client;
use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that writes to Argus Logs. Needs psr/log (any of 1, 2 or 3),
 * which this package suggests rather than requires.
 *
 * `{placeholder}` tokens are interpolated from the context, and the context
 * becomes the record's attributes. A Throwable under the `exception` key is
 * also sent to Argus as an error when the level is error or above (set
 * $captureExceptions false to only log it).
 *
 *     $logger = new PsrLogger();               // uses the client init() made
 *     $logger->warning('Paystack slow: {ms} ms', ['ms' => 2300]);
 */
final class PsrLogger extends AbstractLogger
{
    public function __construct(
        private readonly ?Client $client = null,
        private readonly bool $captureExceptions = true,
    ) {
    }

    /**
     * Untyped $message and context keep the signature compatible with psr/log
     * 1, 2 and 3 at once (a child may widen a parameter type).
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<array-key,mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $client = $this->client ?? Argus::getClient();
        if ($client === null || !$client->isEnabled()) {
            return;
        }
        LogRecordWriter::write($client, is_string($level) ? $level : 'info', (string) $message, $context, $this->captureExceptions);
    }
}
