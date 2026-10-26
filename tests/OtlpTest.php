<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Internal\Otlp;
use NaijaCloud\Argus\Span;
use NaijaCloud\Argus\Tests\Support\MockServer;
use NaijaCloud\Argus\Tests\Support\ServerTestCase;

final class OtlpTest extends ServerTestCase
{
    /** @param array<string,mixed> $body */
    private static function resource(array $body, string $root): array
    {
        return self::attrs($body[$root][0]['resource']['attributes']);
    }

    public function testLogsAreOtlpJsonWithTheResourceAndTraceIds(): void
    {
        $c = $this->client();
        $c->log('info', 'order placed', ['orderId' => 'ord_81', 'total' => 18500, 'paid' => true, 'ratio' => 0.5]);
        $span = null;
        $c->trace('charge', static function (Span $s) use ($c, &$span): void {
            $span = $s;
            $c->log('warning', 'paystack slow');
        });
        $c->log('critical', 'down');
        self::assertTrue($c->flush());

        $reqs = self::$server->requests('/v1/logs');
        self::assertCount(1, $reqs, 'one batch');
        self::assertSame('application/json', $reqs[0]['headers']['content-type']);
        self::assertSame('Bearer ' . MockServer::KEY, $reqs[0]['headers']['authorization']);
        $body = self::json($reqs[0]);
        $res = self::resource($body, 'resourceLogs');
        self::assertSame('shop', $res['service.name']);
        self::assertSame('shop@1.4.2', $res['service.version']);
        self::assertSame('test', $res['deployment.environment']);
        self::assertSame('argus.php', $res['telemetry.sdk.name']);
        self::assertSame('0.1.0', $res['telemetry.sdk.version']);

        $records = $body['resourceLogs'][0]['scopeLogs'][0]['logRecords'];
        self::assertCount(3, $records);
        self::assertSame(9, $records[0]['severityNumber']);
        self::assertSame('INFO', $records[0]['severityText']);
        self::assertSame('order placed', $records[0]['body']['stringValue']);
        self::assertMatchesRegularExpression('/^\d{19}$/', $records[0]['timeUnixNano']);
        $a = self::attrs($records[0]['attributes']);
        self::assertSame(['orderId' => 'ord_81', 'total' => '18500', 'paid' => true, 'ratio' => 0.5], $a);
        self::assertArrayNotHasKey('traceId', $records[0]);
        self::assertSame(13, $records[1]['severityNumber'], 'warning maps to WARN');
        self::assertSame($span->traceId, $records[1]['traceId']);
        self::assertSame($span->spanId, $records[1]['spanId']);
        self::assertSame(21, $records[2]['severityNumber'], 'critical maps to FATAL');
    }

    public function testMetricsAggregateAndUseDeltaTemporality(): void
    {
        $c = $this->client();
        $c->count('orders.placed', 1, ['method' => 'card']);
        $c->count('orders.placed', 2, ['method' => 'card']);
        $c->count('orders.placed', 1, ['method' => 'transfer']);
        $c->gauge('queue.depth', 4);
        $c->gauge('queue.depth', 9);
        $c->distribution('checkout.ms', 30, [], 'ms');
        $c->distribution('checkout.ms', 700, [], 'ms');
        $c->distribution('checkout.ms', 20000, [], 'ms');
        $c->count('nan', NAN);
        self::assertTrue($c->flush());

        $body = self::json(self::$server->requests('/v1/metrics')[0]);
        $metrics = $body['resourceMetrics'][0]['scopeMetrics'][0]['metrics'];
        $byName = [];
        foreach ($metrics as $m) {
            $byName[$m['name']][] = $m;
        }
        self::assertArrayNotHasKey('nan', $byName, 'non-finite values are dropped');
        self::assertCount(2, $byName['orders.placed'], 'one series per attribute set');
        $card = $byName['orders.placed'][0]['sum'];
        self::assertSame(1, $card['aggregationTemporality']);
        self::assertTrue($card['isMonotonic']);
        self::assertSame(3.0, (float) $card['dataPoints'][0]['asDouble']);
        self::assertSame(9.0, (float) $byName['queue.depth'][0]['gauge']['dataPoints'][0]['asDouble']);
        $h = $byName['checkout.ms'][0];
        self::assertSame('ms', $h['unit']);
        $p = $h['histogram']['dataPoints'][0];
        self::assertSame('3', $p['count']);
        self::assertSame(20730.0, (float) $p['sum']);
        self::assertSame(30.0, (float) $p['min']);
        self::assertSame(20000.0, (float) $p['max']);
        self::assertCount(count($p['explicitBounds']) + 1, $p['bucketCounts']);
        self::assertSame('1', $p['bucketCounts'][count($p['bucketCounts']) - 1], 'above the last bound');
    }

    public function testDroppedSeriesHeaderIsLoggedOnceAndNotRetried(): void
    {
        $c = $this->client('dropped');
        $c->count('a');
        $c->flush();
        $c->count('b');
        $c->flush();
        self::assertCount(2, self::$server->requests('/v1/metrics'));
        self::assertCount(1, array_filter($this->debug, static fn ($l) => str_contains($l, 'series limit')));
    }

    public function testSpansAreOtlpWithParentsKindsAndStatus(): void
    {
        $c = $this->client();
        $c->continueTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        $root = $c->startSpan('GET /orders/{id}', ['http.request.method' => 'GET', 'http.route' => '/orders/{id}'], Span::KIND_SERVER, true);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $root->traceId, 'the caller trace is continued');
        $child = null;
        try {
            $c->trace('charge card', static function (Span $s) use (&$child): void {
                $child = $s;
                self::assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $s->traceparent());

                throw new \RuntimeException('declined');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame($root->traceparent(), $c->traceparent(), 'the child is no longer active');
        $root->setAttribute('http.response.status_code', 502)->end();
        self::assertNull($c->activeSpan());
        $c->flush();

        $spans = self::json(self::$server->requests('/v1/traces')[0])['resourceSpans'][0]['scopeSpans'][0]['spans'];
        self::assertCount(2, $spans);
        [$s1, $s2] = $spans;
        self::assertSame('charge card', $s1['name']);
        self::assertSame(1, $s1['kind']);
        self::assertSame($root->spanId, $s1['parentSpanId']);
        self::assertSame(2, $s1['status']['code']);
        self::assertSame('RuntimeException: declined', $s1['status']['message']);
        self::assertSame('GET /orders/{id}', $s2['name']);
        self::assertSame(2, $s2['kind']);
        self::assertSame('00f067aa0ba902b7', $s2['parentSpanId']);
        self::assertSame(['code' => 1], $s2['status']);
        self::assertMatchesRegularExpression('/^\d{19}$/', $s2['startTimeUnixNano']);
        self::assertGreaterThanOrEqual($s2['startTimeUnixNano'], $s2['endTimeUnixNano']);
        self::assertSame('502', self::attrs($s2['attributes'])['http.response.status_code']);
    }

    public function testUnsampledTracesAreNotSent(): void
    {
        $c = $this->client('ok', ['traces_sample_rate' => 0.0]);
        $c->startSpan('x')->end();
        $c->continueTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');
        $c->startSpan('remote says no')->end();
        $c->flush();
        self::assertSame([], self::$server->requests('/v1/traces'));
    }

    public function testNanosKeepPrecision(): void
    {
        self::assertSame('1700000000500000000', Otlp::nanos(1700000000.5));
        self::assertSame('1700000001000000000', Otlp::nanos(1700000000.9999999999));
    }

    public function testBigLogBatchesAreSplit(): void
    {
        $c = $this->client();
        $line = str_repeat('x', 10_000);
        for ($i = 0; $i < 300; $i++) {
            $c->log('info', $line . $i);
        }
        $c->flush();
        $reqs = self::$server->requests('/v1/logs');
        self::assertGreaterThan(1, count($reqs));
        $total = 0;
        foreach ($reqs as $r) {
            self::assertLessThan(1_048_576, strlen($r['body']));
            $total += count(self::json($r)['resourceLogs'][0]['scopeLogs'][0]['logRecords']);
        }
        self::assertSame(300, $total, 'nothing lost in the split');
    }
}
