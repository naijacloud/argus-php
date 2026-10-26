<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Internal;

/**
 * OTLP/HTTP JSON builders (spec section 5). Only what Argus reads, so the SDK
 * needs no protobuf extension.
 *
 * @internal
 */
final class Otlp
{
    public const SEVERITY = ['trace' => 1, 'debug' => 5, 'info' => 9, 'warn' => 13, 'error' => 17, 'fatal' => 21];

    /**
     * @param array<string,mixed> $a
     * @return list<array{key:string,value:array<string,mixed>}>
     */
    public static function attrs(array $a): array
    {
        $out = [];
        foreach ($a as $key => $v) {
            $key = (string) $key;
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $out[] = ['key' => $key, 'value' => ['boolValue' => $v]];
            } elseif (is_int($v)) {
                $out[] = ['key' => $key, 'value' => ['intValue' => (string) $v]];
            } elseif (is_float($v)) {
                $out[] = ['key' => $key, 'value' => is_finite($v) ? ['doubleValue' => $v] : ['stringValue' => (string) $v]];
            } elseif (is_string($v)) {
                $out[] = ['key' => $key, 'value' => ['stringValue' => $v]];
            } else {
                $json = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $out[] = ['key' => $key, 'value' => ['stringValue' => $json === false ? '' : $json]];
            }
        }

        return $out;
    }

    /** Unix nanoseconds as a string (OTLP JSON encodes 64-bit integers as strings). */
    public static function nanos(float $seconds): string
    {
        // Split so the result keeps nanosecond digits without float overflow.
        $whole = (int) floor($seconds);
        $frac = (int) round(($seconds - $whole) * 1e9);
        if ($frac >= 1_000_000_000) {
            $whole++;
            $frac -= 1_000_000_000;
        }

        return $whole . str_pad((string) $frac, 9, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,mixed> $resource
     * @param list<array{time:float,severity:string,body:string,attributes:array<string,mixed>,traceId:?string,spanId:?string}> $records
     * @return array<string,mixed>
     */
    public static function logs(array $resource, array $records): array
    {
        $out = [];
        foreach ($records as $r) {
            $rec = [
                'timeUnixNano' => self::nanos($r['time']),
                'severityNumber' => self::SEVERITY[$r['severity']] ?? 9,
                'severityText' => strtoupper($r['severity']),
                'body' => ['stringValue' => $r['body']],
                'attributes' => self::attrs($r['attributes']),
            ];
            if ($r['traceId'] !== null) {
                $rec['traceId'] = $r['traceId'];
            }
            if ($r['spanId'] !== null) {
                $rec['spanId'] = $r['spanId'];
            }
            $out[] = $rec;
        }

        return ['resourceLogs' => [[
            'resource' => ['attributes' => self::attrs($resource)],
            'scopeLogs' => [['scope' => ['name' => 'argus'], 'logRecords' => $out]],
        ]]];
    }

    /**
     * @param array<string,mixed> $resource
     * @param list<array<string,mixed>> $spans already-shaped OTLP span objects
     * @return array<string,mixed>
     */
    public static function traces(array $resource, array $spans): array
    {
        return ['resourceSpans' => [[
            'resource' => ['attributes' => self::attrs($resource)],
            'scopeSpans' => [['scope' => ['name' => 'argus'], 'spans' => $spans]],
        ]]];
    }

    /**
     * @param array<string,mixed> $resource
     * @param list<array<string,mixed>> $metrics already-shaped OTLP metric objects
     * @return array<string,mixed>
     */
    public static function metrics(array $resource, array $metrics): array
    {
        return ['resourceMetrics' => [[
            'resource' => ['attributes' => self::attrs($resource)],
            'scopeMetrics' => [['scope' => ['name' => 'argus'], 'metrics' => $metrics]],
        ]]];
    }
}
