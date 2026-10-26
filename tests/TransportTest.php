<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Tests\Support\MockServer;
use NaijaCloud\Argus\Tests\Support\ServerTestCase;
use NaijaCloud\Argus\Transport\HttpClient;
use NaijaCloud\Argus\Transport\HttpResponse;
use NaijaCloud\Argus\Transport\NetworkException;
use NaijaCloud\Argus\Transport\StreamHttpClient;
use NaijaCloud\Argus\Transport\Transport;

final class TransportTest extends ServerTestCase
{
    /**
     * The transport sleeps until the retry is due, which is the backoff less
     * the few microseconds the request itself took.
     *
     * @param list<float> $expected
     */
    private function assertBackoff(array $expected, string $message = ''): void
    {
        self::assertCount(count($expected), $this->slept, $message);
        foreach ($expected as $i => $s) {
            self::assertEqualsWithDelta($s, $this->slept[$i], 0.05, $message);
        }
    }

    public function testServerErrorsBackOffAndRetry(): void
    {
        $c = $this->client('flaky');
        $c->captureMessage('x');
        self::assertTrue($c->flush(10_000));
        self::assertCount(3, self::$server->requests('/api/'), 'two 503s, then accepted');
        self::assertBackoff([1.0, 2.0], 'exponential backoff: 1 s, then 2 s');
    }

    public function testGivesUpAfterFiveTries(): void
    {
        $c = $this->client('r500');
        $c->captureMessage('x');
        self::assertTrue($c->flush(60_000), 'the lane is empty after giving up');
        self::assertCount(5, self::$server->requests('/api/'));
        self::assertBackoff([1.0, 2.0, 4.0, 8.0]);
    }

    public function testABackoffPastTheDeadlineIsNotSlept(): void
    {
        $c = $this->client('r500');
        $c->captureMessage('x');
        self::assertFalse($c->flush(1500), 'still queued');
        self::assertCount(2, self::$server->requests('/api/'));
        self::assertBackoff([1.0], 'the 2 s backoff would end past 1.5 s, so it is left queued');
    }

    public function test429BlocksTheEndpointButNotTheOthers(): void
    {
        $c = $this->client('r429');
        $c->captureMessage('first');
        $c->flush();
        $c->captureMessage('second');
        $c->log('info', 'a log');
        $c->flush();
        self::assertCount(1, self::$server->requests('/api/'), 'nothing more on errors until Retry-After');
        self::assertSame([], $this->slept, '429 is not retried with backoff');
        self::assertCount(1, self::$server->requests('/v1/logs'), 'logs are their own lane');
        self::assertSame(30.0, Transport::retryAfter(new HttpResponse(429, ['Retry-After' => '30'])));
    }

    public function testRetryAfterFallbacks(): void
    {
        self::assertSame(45.0, Transport::retryAfter(new HttpResponse(429, ['X-Sentry-Rate-Limits' => '45:error;default:organization'])));
        self::assertSame(60.0, Transport::retryAfter(new HttpResponse(429)));
        self::assertSame(1.0, Transport::retryAfter(new HttpResponse(429, ['Retry-After' => '0.2'])));
        self::assertSame(3600.0, Transport::retryAfter(new HttpResponse(429, ['Retry-After' => '99999'])));
        $date = gmdate('D, d M Y H:i:s', time() + 20) . ' GMT';
        self::assertEqualsWithDelta(20.0, Transport::retryAfter(new HttpResponse(429, ['Retry-After' => $date])), 2.0);
    }

    public function test401StopsTheEndpointUntilRestart(): void
    {
        $c = $this->client('r401');
        $c->captureMessage('first');
        $c->flush();
        $c->captureMessage('second');
        $c->flush();
        self::assertCount(1, self::$server->requests('/api/'));
        self::assertTrue($c->getTransport()->isStopped('errors'));
        self::assertNotEmpty(array_filter($this->debug, static fn ($l) => str_contains($l, 'refused')));
    }

    public function test403StopsItToo(): void
    {
        $c = $this->client('r403');
        $c->log('info', 'x');
        $c->flush();
        self::assertTrue($c->getTransport()->isStopped('logs'));
    }

    public function testOther4xxIsDroppedWithoutRetry(): void
    {
        $c = $this->client('r400');
        $c->captureMessage('x');
        self::assertTrue($c->flush());
        self::assertCount(1, self::$server->requests('/api/'));
        self::assertSame([], $this->slept);
        self::assertSame(400, $c->getTransport()->lastStatus('errors'));
    }

    public function testAnHtml502IsRetriedNotParsed(): void
    {
        $c = $this->client('html');
        $c->captureMessage('x');
        $c->flush(60_000);
        self::assertCount(5, self::$server->requests('/api/'));
    }

    public function testAnUnreachableHostNeverThrowsAndStaysBounded(): void
    {
        $c = new \NaijaCloud\Argus\Client(['dsn' => MockServer::deadDsn(), 'flush_timeout_ms' => 1000]);
        $c->captureException(new \RuntimeException('x'));
        $c->log('info', 'y');
        $start = microtime(true);
        self::assertFalse($c->flush());
        self::assertLessThan(2.5, microtime(true) - $start, 'flush is bounded by its timeout');
    }

    public function testASlowServerIsCutOffAtTheDeadline(): void
    {
        $c = $this->client('slow');
        $c->captureMessage('x');
        $start = microtime(true);
        self::assertFalse($c->flush(500));
        self::assertLessThan(1.5, microtime(true) - $start);
    }

    public function testTheQueueIsBoundedAndDropsTheOldest(): void
    {
        $sent = new \ArrayObject();
        $http = new class ($sent) implements HttpClient {
            /** @param \ArrayObject<int,string> $sent */
            public function __construct(private readonly \ArrayObject $sent)
            {
            }

            public function post(string $url, string $body, array $headers, float $timeout): HttpResponse
            {
                $this->sent[] = $body;

                return new HttpResponse(200);
            }
        };
        $t = new Transport($http);
        for ($i = 0; $i < 150; $i++) {
            $t->enqueue('logs', 'http://x/v1/logs', 'b' . $i, []);
        }
        self::assertFalse($t->enqueue('logs', 'http://x', str_repeat('x', Transport::MAX_BYTES + 1), []), 'over 1 MB is refused');
        $t->flush();
        self::assertCount(100, $sent);
        self::assertSame('b50', $sent[0], 'the oldest 50 were dropped');

        $sent->exchangeArray([]);
        $t2 = new Transport($http);
        for ($i = 0; $i < 5; $i++) {
            $t2->enqueue('errors', 'http://x', str_repeat('y', 300_000), []);
        }
        $t2->flush();
        self::assertCount(3, $sent, 'the byte cap applies too');
    }

    public function testAThrowingHttpClientIsSwallowed(): void
    {
        $http = new class () implements HttpClient {
            public function post(string $url, string $body, array $headers, float $timeout): HttpResponse
            {
                throw new \Error('broken client');
            }
        };
        $c = $this->client('ok', ['http_client' => $http]);
        $c->captureMessage('x');
        self::assertFalse($c->flush(1500));
        self::assertFalse($c->isOn('f', 'u'));
    }

    public function testTheStreamClientWorksWithoutCurl(): void
    {
        $c = $this->client('ok', ['http_client' => new StreamHttpClient()]);
        $c->captureException(new \RuntimeException(str_repeat('big ', 400)));
        $c->log('info', 'over streams');
        self::assertTrue($c->flush());
        self::assertCount(1, self::$server->requests('/api/'));
        self::assertCount(1, self::$server->requests('/v1/logs'));
        self::assertSame('on', $c->variant('new-checkout', 'u1', 'off'));

        $res = (new StreamHttpClient())->post(self::$server->dsn('r429') . '/x', '', [], 2.0);
        self::assertSame(429, $res->status);
        self::assertSame('30', $res->header('Retry-After'));

        $this->expectException(NetworkException::class);
        (new StreamHttpClient())->post(MockServer::deadDsn(), '', [], 1.0);
    }

    public function testCurlClientReportsNetworkErrorsAsNetworkException(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl not loaded');
        }
        $this->expectException(NetworkException::class);
        (new \NaijaCloud\Argus\Transport\CurlHttpClient())->post(MockServer::deadDsn(), '', [], 1.0);
    }

    public function testHeaderParsingKeepsTheLastResponse(): void
    {
        [$status, $h] = HttpResponse::parseHeaderLines(["HTTP/1.1 100 Continue\r\n", "\r\n", "HTTP/1.1 429 Too Many\r\n", "Retry-After: 5\r\n"]);
        self::assertSame(429, $status);
        self::assertSame(['retry-after' => '5'], $h);
    }
}
