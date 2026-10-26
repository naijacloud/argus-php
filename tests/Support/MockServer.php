<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests\Support;

use RuntimeException;

/**
 * A real HTTP server on 127.0.0.1, spawned with `php -S`, standing in for
 * Argus ingest.
 *
 * The point is to exercise the real HTTP clients rather than mock them away:
 * headers, gzip, status handling and the retry loop are things only a socket
 * proves. Nothing leaves the loopback interface, so the suite runs offline.
 */
final class MockServer
{
    public const KEY = 'test0000000000000000000000000000';
    public const PROJECT = '42';

    /** @var resource */
    private $process;

    /** @param resource $process */
    private function __construct($process, public readonly int $port, public readonly string $stateDir)
    {
        $this->process = $process;
    }

    public static function start(): self
    {
        $port = self::freePort();
        $stateDir = sys_get_temp_dir() . '/argus-mock-' . $port . '-' . getmypid();
        if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
            throw new RuntimeException('could not create the mock server state directory');
        }
        // An argv array, not a string: no shell in between, so terminating the
        // process terminates the server itself.
        $command = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'];
        $env = getenv();
        $env['ARGUS_MOCK_STATE'] = $stateDir;
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stateDir . '/server.out', 'a'], 2 => ['file', $stateDir . '/server.err', 'a']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('could not start the mock HTTP server');
        }
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $server = new self($process, $port, $stateDir);
        $server->waitUntilListening();

        return $server;
    }

    /** A DSN whose path prefix picks the router scenario. */
    public function dsn(string $scenario = 'ok'): string
    {
        return 'http://' . self::KEY . '@127.0.0.1:' . $this->port . '/s/' . $scenario . '/' . self::PROJECT;
    }

    /** A DSN pointing at a port nothing listens on. */
    public static function deadDsn(): string
    {
        return 'http://' . self::KEY . '@127.0.0.1:' . self::freePort() . '/' . self::PROJECT;
    }

    /**
     * @return list<array{scenario:string,method:string,path:string,uri:string,headers:array<string,string>,body:string,raw_length:int}>
     */
    public function requests(?string $pathPrefix = null): array
    {
        $file = $this->stateDir . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && ($pathPrefix === null || str_starts_with((string) $d['path'], $pathPrefix))) {
                $out[] = $d;
            }
        }

        return $out;
    }

    public function reset(): void
    {
        foreach (array_merge(glob($this->stateDir . '/requests.jsonl') ?: [], glob($this->stateDir . '/count-*') ?: []) as $f) {
            @unlink($f);
        }
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->stateDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->stateDir);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('could not reserve a port: ' . $error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $c = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.2);
            if (is_resource($c)) {
                fclose($c);

                return;
            }
            usleep(50_000);
        }
        $stderr = @file_get_contents($this->stateDir . '/server.err') ?: '';
        $this->stop();

        throw new RuntimeException('the mock HTTP server did not start within 10s. ' . $stderr);
    }
}
