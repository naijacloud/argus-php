<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Tests\Support\ServerTestCase;

/**
 * The global hooks only show their behaviour in a real process: exit codes,
 * PHP's own error output, and what reaches Argus after the script ends.
 */
final class HandlersTest extends ServerTestCase
{
    /**
     * @param array<string,string> $env
     * @return array{0:int,1:string,2:string}
     */
    private function runScript(string $script, array $env = []): array
    {
        $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'error_reporting=' . E_ALL, __DIR__ . '/Support/scripts/' . $script];
        $env += ['ARGUS_DSN' => self::$server->dsn(), 'PATH' => (string) getenv('PATH')];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    public function testAnUncaughtExceptionIsReportedAndPhpStillFails(): void
    {
        [$code, , $err] = $this->runScript('uncaught.php');
        self::assertSame(255, $code, 'exit code as without Argus');
        self::assertStringContainsString('Uncaught RuntimeException: uncaught in script', $err, "PHP's own message still prints");
        $reqs = self::$server->requests('/api/');
        self::assertCount(1, $reqs, 'reported once, not again as a fatal error');
        $e = self::envelope($reqs[0])[2];
        self::assertSame('fatal', $e['level']);
        self::assertSame('no', $e['tags']['handled']);
        self::assertSame('uncaught in script', $e['exception']['values'][0]['value']);
        $frames = $e['exception']['values'][0]['stacktrace']['frames'];
        self::assertSame('placeOrder', $frames[count($frames) - 1]['function']);
        self::assertSame('{main}', $frames[0]['function']);
        self::assertCount(1, self::$server->requests('/v1/logs'), 'logs buffered before the crash are flushed');
    }

    public function testAPreviousExceptionHandlerIsStillCalled(): void
    {
        [$code, $out] = $this->runScript('uncaught.php', ['WITH_PREVIOUS' => '1']);
        self::assertStringContainsString('previous handler saw: uncaught in script', $out);
        self::assertSame(0, $code, 'PHP exits 0 when a user handler handled it; chaining keeps that');
        self::assertCount(1, self::$server->requests('/api/'));
    }

    public function testWarningsAreReportedAndThePreviousHandlerChained(): void
    {
        [$code, $out] = $this->runScript('warning.php');
        self::assertSame(0, $code);
        self::assertStringContainsString('previous error handler: disk nearly full', $out);
        self::assertStringContainsString('still running', $out);
        $events = array_map(static fn ($r) => self::envelope($r)[2], self::$server->requests('/api/'));
        $values = array_map(static fn ($e) => $e['exception']['values'][0]['value'] ?? $e['message'], $events);
        self::assertContains('User warning: disk nearly full', $values);
        self::assertNotContains('User warning: silenced', $values, '@ is respected');
        self::assertCount(3, $events, 'two warnings on two lines, and the message; deprecations are not events');
        $last = end($events);
        $crumbs = array_map(static fn ($c) => $c['message'], $last['breadcrumbs']['values'] ?? []);
        self::assertContains('User deprecated: old api', $crumbs, 'deprecations become breadcrumbs');
        self::assertSame('warning', $events[0]['level']);
        self::assertSame('ErrorException', $events[0]['exception']['values'][0]['type']);
    }

    public function testAFatalErrorIsReportedFromShutdown(): void
    {
        // The exit code after an out-of-memory fatal varies by PHP build (255
        // on most, 1 on some 8.1 builds), so compare with the same script run
        // without Argus: the SDK must not change it.
        [$bare] = $this->runScript('fatal.php', ['ARGUS_DSN' => '']);
        self::$server->reset();
        [$code, $out, $err] = $this->runScript('fatal.php');
        self::assertNotSame(0, $code);
        self::assertSame($bare, $code, "stdout:\n{$out}\nstderr:\n{$err}");
        self::assertStringContainsString('Allowed memory size', $err);
        $reqs = self::$server->requests('/api/');
        if (PHP_VERSION_ID < 80200 && count($reqs) === 0) {
            // Some PHP 8.1 builds (setup-php's, not the official Docker image)
            // never run shutdown functions after an out-of-memory fatal. The
            // README says so; 8.1 has been out of support since 2025-12.
            self::markTestSkipped('this PHP 8.1 build skips shutdown functions after an out-of-memory fatal');
        }
        self::assertCount(1, $reqs);
        $e = self::envelope($reqs[0])[2];
        self::assertSame('fatal', $e['level']);
        self::assertStringContainsString('Allowed memory size', $e['exception']['values'][0]['value']);
    }

    public function testTheFlushRunsAfterTheAppsOwnShutdownFunctions(): void
    {
        [$code, $out] = $this->runScript('shutdown-order.php');
        self::assertSame(0, $code);
        self::assertSame("ok\n", $out);
        $logs = self::$server->requests('/v1/logs');
        self::assertCount(1, $logs);
        self::assertStringContainsString('from a later shutdown function', $logs[0]['body']);
        self::assertCount(1, self::$server->requests('/v1/metrics'));
    }

    public function testWithoutADsnTheScriptBehavesAsIfArgusWereAbsent(): void
    {
        [$code, $out, $err] = $this->runScript('uncaught.php', ['ARGUS_DSN' => '']);
        self::assertSame(255, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('Uncaught RuntimeException', $err);
        self::assertStringNotContainsString('[argus]', $err);
        self::assertStringNotContainsString('ErrorHandlers', $err);
        self::assertSame([], self::$server->requests());
    }
}
