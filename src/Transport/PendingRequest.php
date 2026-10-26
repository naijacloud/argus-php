<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

/** @internal */
final class PendingRequest
{
    public int $tries = 0;
    public float $notBefore = 0.0;

    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $url,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }
}
