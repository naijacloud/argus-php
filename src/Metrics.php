<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

/** Custom metrics, aggregated in the process and sent at shutdown (or every 10 s). */
final class Metrics
{
    public function __construct(private readonly ?Client $client)
    {
    }

    /**
     * Adds to a counter (delta temporality).
     *
     * @param array<string,mixed> $attributes
     */
    public function count(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->client?->count($name, $value, $attributes);
    }

    /**
     * Sets a gauge to its latest value.
     *
     * @param array<string,mixed> $attributes
     */
    public function gauge(string $name, int|float $value, array $attributes = [], ?string $unit = null): void
    {
        $this->client?->gauge($name, $value, $attributes, $unit);
    }

    /**
     * Records a value into a histogram (durations, sizes).
     *
     * @param array<string,mixed> $attributes
     */
    public function distribution(string $name, int|float $value, array $attributes = [], ?string $unit = null): void
    {
        $this->client?->distribution($name, $value, $attributes, $unit);
    }
}
