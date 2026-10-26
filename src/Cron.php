<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

/** Cron check-ins by the monitor's slug. Create the monitor in Argus first. */
final class Cron
{
    public function __construct(private readonly ?Client $client)
    {
    }

    /** @param 'start'|'ok'|'error'|int $status start, ok, error, or an exit code */
    public function checkIn(string $slug, string|int $status = 'ok', ?string $output = null): void
    {
        $this->client?->checkIn($slug, $status, $output);
    }

    /**
     * Runs $job and reports start, then ok or error. Rethrows the job's error.
     *
     * @template T
     * @param callable():T $job
     * @return T
     */
    public function withMonitor(string $slug, callable $job): mixed
    {
        return $this->client === null ? $job() : $this->client->withMonitor($slug, $job);
    }
}
