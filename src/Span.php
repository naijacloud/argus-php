<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

use NaijaCloud\Argus\Internal\Ids;

/** A unit of work in a trace. End it once. */
final class Span
{
    public const KIND_INTERNAL = 1;
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;
    public const KIND_PRODUCER = 4;
    public const KIND_CONSUMER = 5;

    public readonly string $traceId;
    public readonly string $spanId;
    public readonly ?string $parentSpanId;
    public readonly bool $sampled;
    public readonly float $startTime;
    public string $name;
    /** @var array<string,mixed> */
    public array $attributes;
    private ?string $error = null;
    private bool $ended = false;

    /**
     * @param array{traceId:string,spanId:string,sampled:bool}|null $parent
     * @param array<string,mixed> $attributes
     * @internal use Client::startSpan()
     */
    public function __construct(
        private readonly ?Client $client,
        string $name,
        public readonly int $kind = self::KIND_INTERNAL,
        ?array $parent = null,
        array $attributes = [],
        ?float $startTime = null,
        ?bool $sampled = null,
    ) {
        $this->name = $name;
        $this->attributes = $attributes;
        $this->traceId = $parent['traceId'] ?? Ids::traceId();
        $this->parentSpanId = $parent['spanId'] ?? null;
        $this->spanId = Ids::spanId();
        $this->sampled = $parent !== null ? $parent['sampled'] : ($sampled ?? false);
        $this->startTime = $startTime ?? microtime(true);
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setAttribute(string $key, string|int|float|bool|null $value): self
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /** @param array<string,string|int|float|bool|null> $attributes */
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $k => $v) {
            $this->attributes[(string) $k] = $v;
        }

        return $this;
    }

    /** Marks the span failed; end() records it with an error status. */
    public function setError(string|\Throwable $error): self
    {
        $this->error = $error instanceof \Throwable ? get_class($error) . ': ' . $error->getMessage() : $error;

        return $this;
    }

    /** W3C traceparent, for outgoing requests. */
    public function traceparent(): string
    {
        return '00-' . $this->traceId . '-' . $this->spanId . '-' . ($this->sampled ? '01' : '00');
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /** Ends the span. A Throwable or message marks it failed. */
    public function end(\Throwable|string|null $error = null, ?float $endTime = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        if ($error !== null) {
            $this->setError($error);
        }
        $this->client?->finishSpan($this, $endTime ?? microtime(true), $this->error);
    }

    /**
     * Parses a W3C traceparent header.
     *
     * @return array{traceId:string,spanId:string,sampled:bool}|null
     */
    public static function parseTraceparent(?string $header): ?array
    {
        if ($header === null || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', trim($header), $m) !== 1) {
            return null;
        }
        if (preg_match('/^0+$/', $m[1]) === 1 || preg_match('/^0+$/', $m[2]) === 1) {
            return null;
        }

        return ['traceId' => $m[1], 'spanId' => $m[2], 'sampled' => (hexdec($m[3]) & 1) === 1];
    }
}
