<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

/**
 * One endpoint's queue and state.
 *
 * @internal
 */
final class Lane
{
    /** @var list<PendingRequest> */
    public array $queue = [];
    public int $bytes = 0;
    public float $blockedUntil = 0.0;
    public bool $dead = false;
    public ?int $last = null;

    public function shift(): void
    {
        $old = array_shift($this->queue);
        if ($old !== null) {
            $this->bytes -= strlen($old->body);
        }
    }

    public function clear(): void
    {
        $this->queue = [];
        $this->bytes = 0;
    }
}
