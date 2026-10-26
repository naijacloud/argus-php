<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

/**
 * Sending (spec section 6), shaped for PHP's request lifecycle.
 *
 * Nothing goes out while the app works: requests wait in one queue per
 * endpoint ("lane") and leave in flush(), which the SDK runs at shutdown after
 * the response has been handed back to the web server. flush() is bounded by a
 * deadline, so a slow or unreachable Argus costs the worker at most that long.
 *
 * - Network errors and 5xx back off (1 s, 2 s, 4 s ... capped at 60 s), at most
 *   5 tries. A backoff that would end past the deadline leaves the request
 *   queued for the next flush (long-running workers) instead of sleeping.
 * - 429 blocks the lane until Retry-After, dropping what it holds.
 * - 401/403 stops the lane until the process restarts.
 * - Any other 4xx drops the request.
 *
 * Nothing here throws.
 *
 * @internal
 */
final class Transport
{
    public const MAX_ITEMS = 100;
    public const MAX_BYTES = 1_048_576;
    public const MAX_TRIES = 5;

    /** @var array<string,Lane> */
    private array $lanes = [];

    /** @var \Closure(float):void */
    private \Closure $sleep;

    /** @var \Closure():float */
    private \Closure $now;

    private bool $droppedSeriesLogged = false;

    /**
     * @param (\Closure(string):void)|null $debug
     * @param (callable(float):void)|null $sleep test hook; receives seconds
     * @param (callable():float)|null $now test hook
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly ?\Closure $debug = null,
        ?callable $sleep = null,
        ?callable $now = null,
    ) {
        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static function (float $s): void {
                usleep((int) ($s * 1_000_000));
            };
        $this->now = $now !== null ? \Closure::fromCallable($now) : static fn (): float => microtime(true);
    }

    /**
     * Queues a request on a lane. False when it was dropped (lane stopped or
     * rate limited).
     *
     * @param array<string,string> $headers
     */
    public function enqueue(string $lane, string $url, string $body, array $headers): bool
    {
        $l = $this->lane($lane);
        if ($l->dead) {
            return false;
        }
        if (($this->now)() < $l->blockedUntil) {
            $this->log("{$lane}: rate limited, dropping");

            return false;
        }
        if (strlen($body) > self::MAX_BYTES) {
            $this->log("{$lane}: a request of " . strlen($body) . ' bytes is over the limit, dropping');

            return false;
        }
        $l->queue[] = new PendingRequest($url, $body, $headers);
        $l->bytes += strlen($body);
        while (count($l->queue) > self::MAX_ITEMS || $l->bytes > self::MAX_BYTES) {
            $l->shift();
            $this->log("{$lane}: queue full, dropped the oldest request");
        }

        return true;
    }

    /** Whether anything is waiting to be sent. */
    public function pending(?string $lane = null): bool
    {
        foreach ($this->lanes as $name => $l) {
            if (($lane === null || $lane === $name) && $l->queue !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends what is queued, on every lane or one, until done or the deadline.
     * True when nothing is left queued.
     */
    public function flush(float $timeout = 2.0, ?string $only = null): bool
    {
        try {
            return $this->doFlush($timeout, $only);
        } catch (\Throwable $e) {
            $this->log('flush failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Sends one request now and returns the response (flags need the answer).
     * Applies the same lane rules: a stopped or rate-limited lane is not called.
     *
     * @param array<string,string> $headers
     */
    public function request(string $lane, string $url, string $body, array $headers, float $timeout): ?HttpResponse
    {
        $l = $this->lane($lane);
        if ($l->dead || ($this->now)() < $l->blockedUntil) {
            return null;
        }
        try {
            $res = $this->http->post($url, $body, $headers, $timeout);
        } catch (\Throwable $e) {
            $this->log("{$lane}: network error (" . $e->getMessage() . ')');

            return null;
        }
        $this->classify($lane, $l, $res);

        return $res;
    }

    public function queuedBytes(string $lane): int
    {
        return isset($this->lanes[$lane]) ? $this->lanes[$lane]->bytes : 0;
    }

    /** The last HTTP status a lane saw, or null (argus:test reports it). */
    public function lastStatus(string $lane): ?int
    {
        return isset($this->lanes[$lane]) ? $this->lanes[$lane]->last : null;
    }

    public function isStopped(string $lane): bool
    {
        return isset($this->lanes[$lane]) && $this->lanes[$lane]->dead;
    }

    private function doFlush(float $timeout, ?string $only): bool
    {
        $deadline = ($this->now)() + max(0.0, $timeout);
        while (true) {
            $now = ($this->now)();
            $remaining = $deadline - $now;
            $sentSomething = false;
            $nextWake = INF;
            foreach ($this->lanes as $name => $l) {
                if (($only !== null && $name !== $only) || $l->queue === []) {
                    continue;
                }
                $head = $l->queue[0];
                if ($head->notBefore > $now) {
                    $nextWake = min($nextWake, $head->notBefore);
                    continue;
                }
                if ($remaining <= 0.01) {
                    break;
                }
                $this->attempt($name, $l, $head, min($remaining, 10.0));
                $sentSomething = true;
                $now = ($this->now)();
                $remaining = $deadline - $now;
            }
            if (!$this->pending($only)) {
                return true;
            }
            if ($sentSomething && $remaining > 0.01) {
                continue;
            }
            if ($nextWake === INF || $nextWake >= $deadline) {
                return false;
            }
            ($this->sleep)(max(0.0, $nextWake - ($this->now)()));
        }
    }

    private function attempt(string $name, Lane $l, PendingRequest $req, float $timeout): void
    {
        try {
            $res = $this->http->post($req->url, $req->body, $req->headers, $timeout);
        } catch (\Throwable $e) {
            $this->retryLater($name, $l, $req, 'network error (' . $e->getMessage() . ')');

            return;
        }
        $verdict = $this->classify($name, $l, $res);
        if ($verdict === 'retry') {
            $this->retryLater($name, $l, $req, (string) $res->status);
        } elseif ($verdict === 'done') {
            $l->shift();
        }
        // 'stop': classify() already emptied the lane.
    }

    /**
     * Applies a response to the lane. Returns 'done' (drop the request: sent,
     * or refused for good), 'retry' or 'stop' (the lane was emptied).
     */
    private function classify(string $name, Lane $l, HttpResponse $res): string
    {
        $l->last = $res->status;
        if ($res->header('x-argus-dropped-series') !== null && !$this->droppedSeriesLogged) {
            $this->droppedSeriesLogged = true;
            $this->log("{$name}: Argus dropped some metric series; the workspace is at its series limit");
        }
        if ($res->ok()) {
            return 'done';
        }
        if ($res->status === 429) {
            $wait = self::retryAfter($res);
            $l->blockedUntil = ($this->now)() + $wait;
            $this->log("{$name}: 429, sending nothing on this endpoint for {$wait} s");
            $l->clear();

            return 'stop';
        }
        if ($res->status === 401 || $res->status === 403) {
            $this->log("{$name}: {$res->status}, the key was refused; stopping this endpoint until restart");
            $l->dead = true;
            $l->clear();

            return 'stop';
        }
        if ($res->status >= 400 && $res->status < 500) {
            $this->log("{$name}: {$res->status}, dropped");

            return 'done';
        }

        return 'retry';
    }

    private function retryLater(string $name, Lane $l, PendingRequest $req, string $why): void
    {
        $tries = ++$req->tries;
        if ($tries >= self::MAX_TRIES) {
            $this->log("{$name}: {$why}, giving up after {$tries} tries");
            $l->shift();

            return;
        }
        $delay = min(60.0, (float) (2 ** ($tries - 1)));
        $req->notBefore = ($this->now)() + $delay;
        $this->log("{$name}: {$why}, try {$tries}; next in {$delay} s");
    }

    /**
     * Reads Retry-After (seconds, or an HTTP date), then X-Sentry-Rate-Limits;
     * at least 1 s, at most 1 h, 60 s when neither says.
     */
    public static function retryAfter(HttpResponse $res): float
    {
        $s = 0.0;
        $ra = $res->header('retry-after');
        if ($ra !== null && $ra !== '') {
            if (is_numeric($ra)) {
                $s = (float) $ra;
            } else {
                $at = strtotime($ra);
                $s = $at === false ? 0.0 : (float) ($at - time());
            }
        }
        if ($s <= 0) {
            $rl = $res->header('x-sentry-rate-limits');
            $first = $rl === null ? '' : explode(':', $rl)[0];
            $s = is_numeric($first) && (float) $first > 0 ? (float) $first : 60.0;
        }

        return min(3600.0, max(1.0, $s));
    }

    private function lane(string $name): Lane
    {
        return $this->lanes[$name] ??= new Lane();
    }

    private function log(string $msg): void
    {
        if ($this->debug !== null) {
            try {
                ($this->debug)($msg);
            } catch (\Throwable) {
                // debug output must not break sending
            }
        }
    }
}
