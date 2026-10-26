<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Tests\Support\MockServer;
use NaijaCloud\Argus\Tests\Support\ServerTestCase;

final class CronAndFlagsTest extends ServerTestCase
{
    public function testCheckInsUseTheSlugPathsAndGoOutImmediately(): void
    {
        $c = $this->client();
        $c->checkIn('nightly-invoices', 'start');
        self::assertCount(1, self::$server->requests('/v1/cron/'), 'start is sent when the job starts, not at shutdown');
        $c->checkIn('nightly-invoices', 'ok');
        $c->checkIn('nightly-invoices', 'error', 'failed: password=hunter2');
        $c->checkIn('nightly-invoices', 3);
        $c->checkIn('nightly invoices/2', 0);
        $paths = array_map(static fn ($r) => $r['path'], self::$server->requests('/v1/cron/'));
        self::assertSame([
            '/v1/cron/nightly-invoices/start',
            '/v1/cron/nightly-invoices',
            '/v1/cron/nightly-invoices/fail',
            '/v1/cron/nightly-invoices/3',
            '/v1/cron/nightly%20invoices%2F2/0',
        ], $paths);
        $fail = self::$server->requests('/v1/cron/nightly-invoices/fail')[0];
        self::assertSame('failed: password=[Filtered]', $fail['body']);
        self::assertSame('Bearer ' . MockServer::KEY, $fail['headers']['authorization']);
        self::assertStringStartsWith('text/plain', $fail['headers']['content-type']);
    }

    public function testCheckInOutputIsCappedAt2Kb(): void
    {
        $c = $this->client();
        $c->checkIn('job', 'error', str_repeat('e', 5000));
        self::assertSame(2048, strlen(self::$server->requests('/v1/cron/')[0]['body']));
    }

    public function testWithMonitorReportsSuccessAndFailure(): void
    {
        $c = $this->client();
        self::assertSame(42, $c->withMonitor('job', static fn (): int => 42));
        try {
            $c->withMonitor('job', static function (): never {
                throw new \RuntimeException('disk full');
            });
            self::fail('the job error is rethrown');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }
        $paths = array_map(static fn ($r) => $r['path'], self::$server->requests('/v1/cron/'));
        self::assertSame(['/v1/cron/job/start', '/v1/cron/job', '/v1/cron/job/start', '/v1/cron/job/fail'], $paths);
        self::assertSame('RuntimeException: disk full', self::$server->requests('/v1/cron/job/fail')[0]['body']);
    }

    public function testAnUnknownSlugIs404AndNotAnError(): void
    {
        $c = $this->client();
        $c->checkIn('missing-monitor');
        self::assertSame(404, $c->getTransport()->lastStatus('crons'));
        self::assertFalse($c->getTransport()->isStopped('crons'));
    }

    public function testFlagsAreEvaluatedAndCached(): void
    {
        $c = $this->client();
        self::assertTrue($c->isOn('new-checkout', 'user-1'));
        self::assertFalse($c->isOn('old-banner', 'user-1', true), 'off beats the fallback');
        self::assertSame('dark', $c->variant('theme', 'user-1', 'light'));
        self::assertSame('light', $c->variant('unknown-flag', 'user-1', 'light'), 'unknown flags give the fallback');
        self::assertTrue($c->isOn('unknown-flag', 'user-1', true));
        self::assertCount(1, self::$server->requests('/v1/flags/evaluate'), 'cached per subject for 30 s');

        $req = self::$server->requests('/v1/flags/evaluate')[0];
        self::assertSame('Bearer ' . MockServer::KEY, $req['headers']['authorization']);
        self::assertSame('{"subject":"user-1","environment":"test","attributes":{}}', $req['body']);

        $c->variant('theme', 'user-2', 'light', ['plan' => 'pro']);
        self::assertCount(2, self::$server->requests('/v1/flags/evaluate'));
        self::assertSame(['plan' => 'pro'], self::json(self::$server->requests('/v1/flags/evaluate')[1])['attributes']);
        self::assertSame(['new-checkout' => 'on', 'old-banner' => 'off', 'theme' => 'dark', 'subject-echo' => 'user-3'], $c->evaluateFlags('user-3'));
    }

    public function testFlagsFallBackWhenArgusIsUnreachableOrRefuses(): void
    {
        $dead = new Client(['dsn' => MockServer::deadDsn(), 'flags_timeout_ms' => 500]);
        $start = microtime(true);
        self::assertTrue($dead->isOn('new-checkout', 'u', true));
        self::assertSame('blue', $dead->variant('theme', 'u', 'blue'));
        self::assertLessThan(2.0, microtime(true) - $start);

        $slow = $this->client('slow', ['flags_timeout_ms' => 300]);
        $start = microtime(true);
        self::assertSame('blue', $slow->variant('theme', 'u', 'blue'));
        self::assertLessThan(1.5, microtime(true) - $start, 'flags_timeout_ms bounds the wait');

        $refused = $this->client('r401');
        self::assertFalse($refused->isOn('new-checkout', 'u'));
        self::assertFalse($refused->isOn('new-checkout', 'u2'));
        $refusedCalls = array_filter(self::$server->requests('/v1/flags/'), static fn ($r) => $r['scenario'] === 'r401');
        self::assertCount(1, $refusedCalls, 'a refused key stops the flags endpoint');
        self::assertSame([], $refused->evaluateFlags(''));
    }

    public function testTheStaticNamespacesForwardToTheClient(): void
    {
        Argus::setClient($this->client());
        Argus::cron()->checkIn('job');
        self::assertTrue(Argus::flags()->isOn('new-checkout', 'u'));
        self::assertSame('dark', Argus::flags()->all('u')['theme']);
        Argus::logs()->warn('w');
        Argus::metrics()->gauge('g', 1);
        Argus::trace('t', static fn () => null);
        self::assertTrue(Argus::flush());
        self::assertCount(1, self::$server->requests('/v1/cron/'));
        self::assertCount(1, self::$server->requests('/v1/logs'));
        self::assertCount(1, self::$server->requests('/v1/metrics'));
        self::assertCount(1, self::$server->requests('/v1/traces'));
        self::assertTrue(Argus::close());
        self::assertNull(Argus::getClient());
    }
}
