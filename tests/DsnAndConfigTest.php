<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Dsn;
use NaijaCloud\Argus\Options;
use NaijaCloud\Argus\Span;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DsnAndConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['ARGUS_DSN', 'SENTRY_DSN', 'ARGUS_RELEASE', 'SENTRY_RELEASE', 'ARGUS_ENVIRONMENT', 'SENTRY_ENVIRONMENT'] as $v) {
            unset($_SERVER[$v], $_ENV[$v]);
            putenv($v);
        }
        Argus::setClient(null);
    }

    public function testParsesTheProductionDsn(): void
    {
        $d = Dsn::parse('https://abc123@argus-in.naijacloud.com/41');
        self::assertNotNull($d);
        self::assertSame('abc123', $d->publicKey);
        self::assertSame('41', $d->projectId);
        self::assertSame('https://argus-in.naijacloud.com/api/41/envelope/', $d->envelopeUrl());
        self::assertSame('https://argus-in.naijacloud.com/v1/logs', $d->ingestUrl('/v1/logs'));
    }

    public function testKeepsAPathPrefixAndPort(): void
    {
        $d = Dsn::parse('http://k@127.0.0.1:9000/proxy/argus/7');
        self::assertNotNull($d);
        self::assertSame('http://127.0.0.1:9000/proxy/argus', $d->origin);
        self::assertSame('http://127.0.0.1:9000/proxy/argus/api/7/envelope/', $d->envelopeUrl());
    }

    /** @return iterable<string,array{0:string}> */
    public static function badDsns(): iterable
    {
        yield 'empty' => [''];
        yield 'no key' => ['https://argus-in.naijacloud.com/41'];
        yield 'no project' => ['https://k@argus-in.naijacloud.com/'];
        yield 'non-numeric project' => ['https://k@argus-in.naijacloud.com/abc'];
        yield 'not a url' => ['not a dsn'];
        yield 'ftp' => ['ftp://k@host/1'];
    }

    #[DataProvider('badDsns')]
    public function testRejectsWhatIsNotADsnWithoutThrowing(string $raw): void
    {
        self::assertNull(Dsn::parse($raw));
        $c = new Client(['dsn' => $raw]);
        self::assertFalse($c->isEnabled());
        self::assertSame('', $c->captureException(new \RuntimeException('x')));
    }

    public function testTheDsnIsKeptOutOfVarDump(): void
    {
        $d = Dsn::parse('https://secretish@argus-in.naijacloud.com/41');
        self::assertStringNotContainsString('secretish', print_r($d, true));
    }

    public function testConfigOrderIsOptionThenArgusThenSentry(): void
    {
        $_SERVER['SENTRY_DSN'] = 'https://sentry@h/1';
        self::assertSame('https://sentry@h/1', (new Options())->dsn);
        $_SERVER['ARGUS_DSN'] = 'https://argus@h/2';
        self::assertSame('https://argus@h/2', (new Options())->dsn);
        self::assertSame('https://opt@h/3', (new Options(['dsn' => 'https://opt@h/3']))->dsn);

        $_SERVER['SENTRY_RELEASE'] = 's-rel';
        self::assertSame('s-rel', (new Options())->release);
        putenv('ARGUS_RELEASE=a-rel');
        self::assertSame('a-rel', (new Options())->release, 'getenv() is read too');

        self::assertSame('production', (new Options())->environment);
        $_ENV['SENTRY_ENVIRONMENT'] = 'staging';
        self::assertSame('staging', (new Options())->environment);
        $_SERVER['ARGUS_ENVIRONMENT'] = 'preview';
        self::assertSame('preview', (new Options())->environment);
        self::assertSame('qa', (new Options(['environment' => 'qa']))->environment);
    }

    public function testEnabledFalseTurnsItOff(): void
    {
        $c = new Client(['dsn' => 'https://k@h/1', 'enabled' => false]);
        self::assertFalse($c->isEnabled());
    }

    public function testBadOptionTypesFallBackToDefaults(): void
    {
        $o = new Options(['sample_rate' => 'lots', 'max_breadcrumbs' => 'many', 'before_send' => 'not callable', 'traces_sample_rate' => 7]);
        self::assertSame(1.0, $o->sampleRate);
        self::assertSame(100, $o->maxBreadcrumbs);
        self::assertNull($o->beforeSend);
        self::assertSame(1.0, $o->tracesSampleRate, 'clamped to 1');
    }

    public function testTheStaticApiIsANoOpBeforeInit(): void
    {
        Argus::setClient(null);
        self::assertSame('', Argus::captureException(new \RuntimeException('x')));
        self::assertSame('', Argus::captureMessage('x'));
        Argus::logs()->info('x');
        Argus::metrics()->count('x');
        Argus::cron()->checkIn('x');
        self::assertTrue(Argus::flags()->isOn('x', 'u', true));
        self::assertSame('blue', Argus::flags()->variant('x', 'u', 'blue'));
        self::assertSame(3, Argus::trace('t', static fn (Span $s): int => 3));
        self::assertSame('done', Argus::cron()->withMonitor('x', static fn (): string => 'done'));
        self::assertNull(Argus::traceparent());
        self::assertTrue(Argus::flush());
        self::assertFalse(Argus::isEnabled());
    }

    public function testTraceparentParsing(): void
    {
        $p = Span::parseTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        self::assertSame(['traceId' => '4bf92f3577b34da6a3ce929d0e0e4736', 'spanId' => '00f067aa0ba902b7', 'sampled' => true], $p);
        self::assertFalse(Span::parseTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00')['sampled'] ?? true);
        self::assertNull(Span::parseTraceparent('00-00000000000000000000000000000000-00f067aa0ba902b7-01'));
        self::assertNull(Span::parseTraceparent('garbage'));
        self::assertNull(Span::parseTraceparent(null));
    }

    public function testFunctionStyleInitExists(): void
    {
        $c = \NaijaCloud\Argus\init(['dsn' => null, 'register_handlers' => false]);
        self::assertSame($c, Argus::getClient());
        self::assertFalse($c->isEnabled());
    }
}
