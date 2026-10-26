<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Tests\Support\MockServer;
use NaijaCloud\Argus\Tests\Support\ServerTestCase;

final class ErrorEnvelopeTest extends ServerTestCase
{
    private function boom(): \RuntimeException
    {
        try {
            json_decode('{nope', false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $cause) {
            return new \RuntimeException('checkout failed', 3, $cause);
        }
        throw new \LogicException('unreachable');
    }

    public function testAnExceptionArrivesAsASentryEnvelope(): void
    {
        $c = $this->client();
        $c->setUser(['id' => 'u_81', 'email' => 'ada@example.com', 'ip_address' => '10.0.0.9']);
        $c->setTag('region', 'af-west');
        $c->addBreadcrumb(['category' => 'checkout', 'message' => 'clicked pay']);
        $id = $c->captureException($this->boom(), ['tags' => ['provider' => 'paystack'], 'extra' => ['orderId' => 'ord_81']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertTrue($c->flush());

        $reqs = self::$server->requests('/api/');
        self::assertCount(1, $reqs);
        $r = $reqs[0];
        self::assertSame('/api/' . MockServer::PROJECT . '/envelope/', $r['path']);
        self::assertSame('application/x-sentry-envelope', $r['headers']['content-type']);
        self::assertSame('gzip', $r['headers']['content-encoding'] ?? null, 'bodies over 1 KB are gzipped');
        self::assertStringContainsString('sentry_key=' . MockServer::KEY, $r['headers']['x-sentry-auth']);
        self::assertStringContainsString('sentry_version=7', $r['headers']['x-sentry-auth']);
        self::assertStringStartsWith('argus.php/0.1.0', $r['headers']['user-agent']);

        [$head, $item, $event] = self::envelope($r);
        self::assertSame($id, $head['event_id']);
        self::assertSame(self::$server->dsn(), $head['dsn']);
        self::assertSame(['name' => 'argus.php', 'version' => '0.1.0'], $head['sdk']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $head['sent_at']);
        self::assertSame(['type' => 'event', 'content_type' => 'application/json'], $item);

        self::assertSame($id, $event['event_id']);
        self::assertSame('php', $event['platform']);
        self::assertSame('error', $event['level']);
        self::assertSame('shop@1.4.2', $event['release']);
        self::assertSame('test', $event['environment']);
        self::assertSame(['name' => 'argus.php', 'version' => '0.1.0'], $event['sdk']);
        self::assertIsFloat($event['timestamp'] + 0.0);
        self::assertSame(['region' => 'af-west', 'provider' => 'paystack'], $event['tags']);
        self::assertSame(['id' => 'u_81', 'email' => 'ada@example.com'], $event['user'], 'no ip_address without send_default_pii');
        self::assertSame('ord_81', $event['extra']['orderId']);
        self::assertSame('clicked pay', $event['breadcrumbs']['values'][0]['message']);
        self::assertSame('php', $event['contexts']['runtime']['name']);

        $values = $event['exception']['values'];
        self::assertCount(2, $values);
        self::assertSame('JsonException', $values[0]['type'], 'causes come first');
        self::assertSame('RuntimeException', $values[1]['type'], 'the error itself is last');
        self::assertSame('checkout failed', $values[1]['value']);
        $frames = $values[1]['stacktrace']['frames'];
        $last = $frames[count($frames) - 1];
        self::assertSame(__FILE__, $last['abs_path'], 'the newest frame is last');
        self::assertSame(self::class . '->boom', $last['function']);
        self::assertSame(self::class, $last['module']);
        self::assertIsInt($last['lineno']);
        self::assertStringContainsString('checkout failed', $last['context_line']);
        self::assertNotEmpty($last['pre_context']);
        self::assertTrue($last['in_app']);
        $vendor = array_values(array_filter($frames, static fn ($f) => str_contains((string) $f['abs_path'], '/vendor/')));
        self::assertNotEmpty($vendor);
        self::assertFalse($vendor[0]['in_app'], 'vendor frames are not the app');
    }

    public function testSmallEventsAreNotCompressed(): void
    {
        $c = $this->client('ok', ['context_lines' => false, 'server_name' => 'w1']);
        $c->captureMessage('hi');
        $c->flush();
        $r = self::$server->requests('/api/')[0];
        self::assertArrayNotHasKey('content-encoding', $r['headers']);
        [, , $event] = self::envelope($r);
        self::assertSame('hi', $event['message']);
        self::assertSame('info', $event['level']);
        self::assertSame('w1', $event['server_name']);
    }

    public function testCaptureMessageTakesALevel(): void
    {
        $c = $this->client();
        $c->captureMessage('Webhook signature mismatch', 'warning');
        $c->captureMessage('bad level falls back', 'loud');
        $c->flush();
        $levels = array_map(static fn ($r) => self::envelope($r)[2]['level'], self::$server->requests('/api/'));
        self::assertSame(['warning', 'error'], $levels);
    }

    public function testTheSameThrowableIsSentOnce(): void
    {
        $c = $this->client();
        $e = new \RuntimeException('once');
        self::assertNotSame('', $c->captureException($e));
        self::assertSame('', $c->captureException($e));
        self::assertTrue($c->wasCaptured($e));
        $c->flush();
        self::assertCount(1, self::$server->requests('/api/'));
    }

    public function testBeforeSendCanChangeOrDrop(): void
    {
        $c = $this->client('ok', ['before_send' => static function (array $e): ?array {
            if (($e['message'] ?? '') === 'drop me') {
                return null;
            }
            $e['tags']['seen'] = 'yes';

            return $e;
        }]);
        self::assertSame('', $c->captureMessage('drop me'));
        $c->captureMessage('keep me');
        $c->flush();
        $reqs = self::$server->requests('/api/');
        self::assertCount(1, $reqs);
        self::assertSame('yes', self::envelope($reqs[0])[2]['tags']['seen']);
    }

    public function testABeforeSendThatThrowsSendsTheEventUnchanged(): void
    {
        $c = $this->client('ok', ['before_send' => static function (): never {
            throw new \RuntimeException('bad hook');
        }]);
        self::assertNotSame('', $c->captureMessage('still sent'));
        $c->flush();
        self::assertCount(1, self::$server->requests('/api/'));
    }

    public function testSampleRateZeroSendsNothing(): void
    {
        $c = $this->client('ok', ['sample_rate' => 0.0]);
        self::assertSame('', $c->captureMessage('x'));
        $c->flush();
        self::assertSame([], self::$server->requests());
    }

    public function testIgnoredExceptionClassesAreNotSent(): void
    {
        $c = $this->client('ok', ['ignore_exceptions' => [\DomainException::class]]);
        self::assertSame('', $c->captureException(new \DomainException('expected')));
        $c->flush();
        self::assertSame([], self::$server->requests());
    }

    public function testBreadcrumbsKeepTheLastHundred(): void
    {
        $c = $this->client();
        for ($i = 0; $i < 130; $i++) {
            $c->addBreadcrumb(['message' => 'c' . $i]);
        }
        $c->captureMessage('x');
        $c->flush();
        $crumbs = self::envelope(self::$server->requests('/api/')[0])[2]['breadcrumbs']['values'];
        self::assertCount(100, $crumbs);
        self::assertSame('c30', $crumbs[0]['message']);
        self::assertSame('c129', $crumbs[99]['message']);
    }

    public function testFingerprintTransactionAndSendDefaultPii(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $c = $this->client('ok', ['send_default_pii' => true]);
        $c->setUser(['id' => 7, 'ip_address' => '10.9.9.9']);
        $c->captureMessage('x', 'info', ['fingerprint' => ['checkout', 'paystack'], 'transaction' => 'POST /checkout']);
        $c->flush();
        unset($_SERVER['REMOTE_ADDR']);
        $e = self::envelope(self::$server->requests('/api/')[0])[2];
        self::assertSame(['checkout', 'paystack'], $e['fingerprint']);
        self::assertSame('POST /checkout', $e['transaction']);
        self::assertSame('7', $e['user']['id']);
        self::assertSame('10.9.9.9', $e['user']['ip_address'], 'an explicit ip is kept with send_default_pii');
    }

    public function testEventsInsideASpanCarryTheTraceAndTransaction(): void
    {
        $c = $this->client();
        $root = $c->startSpan('GET /orders/{id}', [], \NaijaCloud\Argus\Span::KIND_SERVER, true);
        $c->trace('load order', static fn () => $c->captureMessage('inside'));
        $root->end();
        $c->flush();
        $e = self::envelope(self::$server->requests('/api/')[0])[2];
        self::assertSame($root->traceId, $e['contexts']['trace']['trace_id']);
        self::assertSame('GET /orders/{id}', $e['transaction'], 'the root span names the transaction');
    }

    public function testClearScopeForgetsTheRequestScope(): void
    {
        $c = $this->client('ok', ['tags' => ['app' => 'shop']]);
        $c->setUser(['id' => '1']);
        $c->setTag('job', 'x');
        $c->addBreadcrumb(['message' => 'old']);
        $c->clearScope();
        $c->captureMessage('fresh');
        $c->flush();
        $e = self::envelope(self::$server->requests('/api/')[0])[2];
        self::assertArrayNotHasKey('user', $e);
        self::assertArrayNotHasKey('breadcrumbs', $e);
        self::assertSame(['app' => 'shop'], $e['tags'], 'init tags stay');
    }

    public function testEmptyMapsAreNotSentAsJsonArrays(): void
    {
        $c = $this->client();
        $c->captureMessage('x', 'info', ['extra' => ['empty' => []]]);
        $c->flush();
        $raw = explode("\n", self::$server->requests('/api/')[0]['body'])[2];
        self::assertStringNotContainsString('[]', $raw);
    }

    public function testClosedClientSendsNothing(): void
    {
        $c = $this->client();
        $c->close();
        self::assertSame('', $c->captureMessage('late'));
        self::assertFalse($c->isEnabled());
    }

    public function testDsnInTheEnvironmentIsEnough(): void
    {
        $_SERVER['ARGUS_DSN'] = self::$server->dsn();
        try {
            $c = new Client(['sleeper' => static function (): void {
            }]);
            self::assertTrue($c->isEnabled());
            $c->captureMessage('from env');
            $c->flush();
        } finally {
            unset($_SERVER['ARGUS_DSN']);
        }
        self::assertCount(1, self::$server->requests('/api/'));
    }
}
