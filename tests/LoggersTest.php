<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use Monolog\Logger;
use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Log\MonologHandler;
use NaijaCloud\Argus\Log\PsrLogger;
use NaijaCloud\Argus\Tests\Support\ServerTestCase;
use Psr\Log\LoggerInterface;

final class LoggersTest extends ServerTestCase
{
    /** @return list<array<string,mixed>> */
    private function records(): array
    {
        $out = [];
        foreach (self::$server->requests('/v1/logs') as $r) {
            foreach (self::json($r)['resourceLogs'][0]['scopeLogs'][0]['logRecords'] as $rec) {
                $out[] = $rec;
            }
        }

        return $out;
    }

    public function testPsrLoggerInterpolatesAndSendsContextAsAttributes(): void
    {
        $c = $this->client();
        $logger = new PsrLogger($c);
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->warning('Paystack slow: {ms} ms', ['ms' => 2300, 'provider' => 'paystack']);
        $logger->notice('note');
        $c->flush();
        $recs = $this->records();
        self::assertSame('Paystack slow: 2300 ms', $recs[0]['body']['stringValue']);
        self::assertSame('WARN', $recs[0]['severityText']);
        self::assertSame(['ms' => '2300', 'provider' => 'paystack'], self::attrs($recs[0]['attributes']));
        self::assertSame('INFO', $recs[1]['severityText'], 'notice maps to info');
    }

    public function testPsrLoggerSendsAnErrorForAnExceptionOnce(): void
    {
        Argus::setClient($c = $this->client());
        $logger = new PsrLogger();
        $e = new \RuntimeException('db down');
        $logger->error('query failed', ['exception' => $e]);
        $logger->critical('again', ['exception' => $e]);
        $logger->info('info with exception', ['exception' => new \RuntimeException('not sent as error')]);
        $c->flush();
        self::assertCount(1, self::$server->requests('/api/'), 'once, and info level does not become an error');
        $a = self::attrs($this->records()[0]['attributes']);
        self::assertSame('RuntimeException', $a['exception.type']);
    }

    public function testMonologHandler(): void
    {
        $c = $this->client();
        $log = new Logger('payments', [new MonologHandler($c, 200)]);
        $log->debug('below the level');
        $log->info('charged {amount}', ['amount' => 500, 'token' => 'tok_1']);
        $log->error('charge failed', ['exception' => new \LogicException('bad state')]);
        $c->flush();
        $recs = $this->records();
        self::assertCount(2, $recs);
        self::assertSame('charged 500', $recs[0]['body']['stringValue']);
        $a = self::attrs($recs[0]['attributes']);
        self::assertSame('payments', $a['log.channel']);
        self::assertSame('[Filtered]', $a['token']);
        self::assertSame('ERROR', $recs[1]['severityText']);
        $event = self::envelope(self::$server->requests('/api/')[0])[2];
        self::assertSame('LogicException', $event['exception']['values'][0]['type']);
    }

    public function testLoggersWithoutAClientDoNothing(): void
    {
        Argus::setClient(null);
        (new PsrLogger())->error('nobody listening');
        (new Logger('x', [new MonologHandler()]))->error('nobody');
        self::assertSame([], self::$server->requests());
    }
}
