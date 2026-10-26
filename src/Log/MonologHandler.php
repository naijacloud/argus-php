<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Log;

use Monolog\Handler\AbstractProcessingHandler;
use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Client;

/**
 * A Monolog handler (Monolog 2 or 3) that writes records to Argus Logs.
 * Monolog is not a requirement of this package; this class only loads when
 * the app has it.
 *
 *     $log = new Monolog\Logger('app', [new MonologHandler()]);
 *
 * A Throwable in the record's `exception` context is also sent as an Argus
 * error at error level and above, once, even if another handler reports it.
 */
final class MonologHandler extends AbstractProcessingHandler
{
    /**
     * @param value-of<\Monolog\Level::VALUES>|value-of<\Monolog\Level::NAMES>|\Monolog\Level|\Psr\Log\LogLevel::* $level
     *        the lowest level handled, in Monolog's own terms
     */
    public function __construct(
        private readonly ?Client $client = null,
        $level = 100,
        bool $bubble = true,
        private readonly bool $captureExceptions = true,
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Untyped so one class serves Monolog 2 (an array) and Monolog 3 (a
     * LogRecord, which is also ArrayAccess with the same keys).
     *
     * @param array<string,mixed>|\Monolog\LogRecord $record
     */
    protected function write($record): void
    {
        $client = $this->client ?? Argus::getClient();
        if ($client === null || !$client->isEnabled()) {
            return;
        }
        $context = is_array($record['context'] ?? null) ? $record['context'] : [];
        $extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];
        foreach ($extra as $k => $v) {
            $context['extra.' . $k] ??= $v;
        }
        $level = is_string($record['level_name'] ?? null) ? $record['level_name'] : 'INFO';
        $channel = is_string($record['channel'] ?? null) ? $record['channel'] : null;
        LogRecordWriter::write($client, strtolower($level), (string) ($record['message'] ?? ''), $context, $this->captureExceptions, $channel);
    }
}
