<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

/**
 * Logs for Argus Logs (OTLP), batched and sent at shutdown or every 5 s in a
 * long-running process. A line written inside a span carries its trace id.
 * For PSR-3 or Monolog, see Log\PsrLogger and Log\MonologHandler.
 */
final class Logs
{
    public function __construct(private readonly ?Client $client)
    {
    }

    /** @param array<string,mixed> $attributes */
    public function trace(string|\Stringable $message, array $attributes = []): void
    {
        $this->client?->log('trace', $message, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function debug(string|\Stringable $message, array $attributes = []): void
    {
        $this->client?->log('debug', $message, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function info(string|\Stringable $message, array $attributes = []): void
    {
        $this->client?->log('info', $message, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function warn(string|\Stringable $message, array $attributes = []): void
    {
        $this->client?->log('warn', $message, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function error(string|\Stringable $message, array $attributes = []): void
    {
        $this->client?->log('error', $message, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function fatal(string|\Stringable $message, array $attributes = []): void
    {
        $this->client?->log('fatal', $message, $attributes);
    }
}
