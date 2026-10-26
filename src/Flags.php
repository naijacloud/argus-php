<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

/**
 * Feature flags, evaluated by Argus for a subject (usually a user id). Answers
 * are cached for 30 s per subject; when Argus is unreachable, or a flag does
 * not exist, the fallback is returned.
 */
final class Flags
{
    public function __construct(private readonly ?Client $client)
    {
    }

    /** @param array<string,string> $attributes */
    public function isOn(string $key, string $subject, bool $fallback = false, array $attributes = []): bool
    {
        return $this->client?->isOn($key, $subject, $fallback, $attributes) ?? $fallback;
    }

    /** @param array<string,string> $attributes */
    public function variant(string $key, string $subject, string $fallback, array $attributes = []): string
    {
        return $this->client?->variant($key, $subject, $fallback, $attributes) ?? $fallback;
    }

    /**
     * @param array<string,string> $attributes
     * @return array<string,string>
     */
    public function all(string $subject, array $attributes = []): array
    {
        return $this->client?->evaluateFlags($subject, $attributes) ?? [];
    }
}
