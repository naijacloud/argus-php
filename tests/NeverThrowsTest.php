<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Tests\Support\ServerTestCase;

/** Spec section 6: SDK code never throws into the app, whatever it is handed. */
final class NeverThrowsTest extends ServerTestCase
{
    public function testHostileValuesAreSentNotThrown(): void
    {
        $c = $this->client();
        $res = fopen('php://memory', 'r');
        $loop = new \stdClass();
        $loop->loop = $loop;
        $bad = "caf\xC3 \xFF invalid utf-8";
        $c->setContext('weird', ['res' => $res, 'closure' => static fn () => 1, 'loop' => $loop, 'inf' => INF, 'bad' => $bad]);
        $c->setUser(['id' => $bad]);
        $c->setTag($bad, $bad);
        $c->addBreadcrumb(['message' => $bad, 'data' => ['obj' => new \ArrayObject([1, 2])]]);
        self::assertNotSame('', $c->captureException(new \RuntimeException($bad)));
        $c->log('info', $bad, ['res' => $res, 'nested' => ['a' => ['b' => $loop]], 'nan' => NAN]);
        $c->count($bad, 1, ['k' => $res]);
        $c->startSpan($bad, ['x' => $bad])->end($bad);
        $c->checkIn($bad, 'start', $bad);
        $c->isOn($bad, $bad, false, ['a' => $bad]);
        self::assertTrue($c->flush());
        fclose($res);

        $event = self::envelope(self::$server->requests('/api/')[0])[2];
        self::assertSame('RuntimeException', $event['exception']['values'][0]['type']);
        self::assertNotEmpty(self::$server->requests('/v1/logs'));
        self::assertNotEmpty(self::$server->requests('/v1/traces'));
    }

    public function testThrowingHooksDoNotEscape(): void
    {
        $c = $this->client('ok', [
            'before_breadcrumb' => static function (): never {
                throw new \Error('hook');
            },
            'logger' => static function (): never {
                throw new \Error('logger');
            },
        ]);
        $c->addBreadcrumb(['message' => 'x']);
        self::assertNotSame('', $c->captureMessage('still fine'));
        self::assertTrue($c->flush());
    }

    public function testNothingPrintsWithoutDebug(): void
    {
        $c = new \NaijaCloud\Argus\Client(['dsn' => self::$server->dsn('r500'), 'flush_timeout_ms' => 200]);
        ob_start();
        $c->captureMessage('x');
        $c->flush();
        self::assertSame('', ob_get_clean());
    }
}
