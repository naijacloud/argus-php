<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests\Support;

use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Client;
use NaijaCloud\Argus\Internal\ErrorHandlers;
use PHPUnit\Framework\TestCase;

/** Base for tests that drive the real HTTP clients against the local stub. */
abstract class ServerTestCase extends TestCase
{
    protected static MockServer $server;

    /** @var list<float> backoff the client would have spent, recorded instead of slept */
    protected array $slept = [];

    /** @var list<string> */
    protected array $debug = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = MockServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
        $this->slept = [];
        $this->debug = [];
    }

    protected function tearDown(): void
    {
        Argus::setClient(null);
        ErrorHandlers::reset();
    }

    /** @param array<string,mixed> $options */
    protected function client(string $scenario = 'ok', array $options = []): Client
    {
        return new Client($options + [
            'dsn' => self::$server->dsn($scenario),
            'release' => 'shop@1.4.2',
            'environment' => 'test',
            'service_name' => 'shop',
            'sleeper' => function (float $s): void {
                $this->slept[] = $s;
            },
            'logger' => function (string $line): void {
                $this->debug[] = $line;
            },
        ]);
    }

    /**
     * @param array{body:string} $request
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    protected static function envelope(array $request): array
    {
        $lines = explode("\n", rtrim($request['body'], "\n"));
        self::assertCount(3, $lines, 'an envelope is a header, an item header and a payload');

        return [
            (array) json_decode($lines[0], true),
            (array) json_decode($lines[1], true),
            (array) json_decode($lines[2], true),
        ];
    }

    /**
     * @param array{body:string} $request
     * @return array<string,mixed>
     */
    protected static function json(array $request): array
    {
        $d = json_decode($request['body'], true);
        self::assertIsArray($d, 'body is JSON');

        return $d;
    }

    /**
     * OTLP attribute list to a map.
     *
     * @param list<array{key:string,value:array<string,mixed>}> $attrs
     * @return array<string,mixed>
     */
    protected static function attrs(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $a) {
            $out[$a['key']] = array_values($a['value'])[0];
        }

        return $out;
    }
}
