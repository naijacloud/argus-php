<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Tests;

use NaijaCloud\Argus\Internal\Scrubber;
use NaijaCloud\Argus\Tests\Support\ServerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScrubbingTest extends ServerTestCase
{
    /** @return iterable<array{0:string,1:bool}> */
    public static function keys(): iterable
    {
        foreach (['password', 'user_password', 'Passwd', 'client-secret', 'accessToken', 'X-Api-Key', 'aws_access_key',
            'Authorization', 'credentials', 'Set-Cookie', 'private_key', 'card_number', 'creditCard', 'bvn', 'cvv', 'CVC',
            'session_id', 'sessionToken', 'signature', 'auth', 'pwd', 'PIN', 'otp', 'session', 'card', 'sid'] as $k) {
            yield $k => [$k, true];
        }
        foreach (['user', 'email', 'orderId', 'author', 'pinned', 'cards_shown', 'sidebar', 'amount'] as $k) {
            yield $k => [$k, false];
        }
    }

    #[DataProvider('keys')]
    public function testSensitiveKeys(string $key, bool $sensitive): void
    {
        self::assertSame($sensitive, Scrubber::sensitiveKey($key));
    }

    public function testStringsLoseCardsTokensAndJwts(): void
    {
        self::assertSame('card [Filtered] ok', Scrubber::string('card 4111 1111 1111 1111 ok'));
        self::assertSame('order 4111111111111112', Scrubber::string('order 4111111111111112'), 'fails Luhn, kept');
        self::assertSame('Authorization: Bearer [Filtered]', Scrubber::string('Authorization: Bearer abcdefghijklmnop'));
        self::assertSame('Basic [Filtered]', Scrubber::string('Basic dXNlcjpwYXNzd29yZA=='));
        self::assertSame('jwt [Filtered]', Scrubber::string('jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.abcdefghijk'));
        self::assertSame('login password=[Filtered] user=ada', Scrubber::string('login password=hunter2 user=ada'));
        self::assertSame('{"api_key": "[Filtered]"}', Scrubber::string('{"api_key": "sk_live_123"}'));
    }

    public function testUrlsLoseTheirQuery(): void
    {
        self::assertSame('https://shop.ng/pay', Scrubber::stripQuery('https://shop.ng/pay?token=abc#x'));
    }

    public function testDeepValuesAreScrubbedAndCyclesCut(): void
    {
        $o = new \stdClass();
        $o->self = $o;
        $out = Scrubber::value(['a' => ['b' => ['password' => 'x', 'ok' => 'y']], 'loop' => $o, 'list' => ['secret', 'x']]);
        self::assertSame('[Filtered]', $out['a']['b']['password']);
        self::assertSame('y', $out['a']['b']['ok']);
        self::assertSame(['secret', 'x'], $out['list'], 'list values are not keys');
        self::assertIsArray($out['loop']);
    }

    public function testAnEventIsScrubbedEverywhere(): void
    {
        $c = $this->client();
        $c->setContext('payment', ['card_number' => '4111111111111111', 'provider' => 'paystack']);
        $c->addBreadcrumb(['message' => 'login', 'data' => ['otp' => '123456', 'url' => 'https://x.ng/a']]);
        $c->captureException(new \RuntimeException('failed for password=hunter2'), [
            'extra' => ['password' => 'hunter2', 'nested' => ['token' => 't'], 'note' => 'Bearer abcdefghijklmnop'],
            'request' => ['method' => 'POST', 'url' => 'https://x.ng/pay', 'headers' => ['Authorization' => 'Bearer x', 'Accept' => 'json'], 'cookies' => ['sid' => '1'], 'data' => ['card' => '1']],
        ]);
        $c->flush();
        $e = self::envelope(self::$server->requests('/api/')[0])[2];
        self::assertSame('[Filtered]', $e['extra']['password']);
        self::assertSame('[Filtered]', $e['extra']['nested']['token']);
        self::assertSame('Bearer [Filtered]', $e['extra']['note']);
        self::assertSame('[Filtered]', $e['contexts']['payment']['card_number']);
        self::assertSame('paystack', $e['contexts']['payment']['provider']);
        self::assertSame('[Filtered]', $e['breadcrumbs']['values'][0]['data']['otp']);
        self::assertSame('[Filtered]', $e['request']['headers']['Authorization']);
        self::assertSame('json', $e['request']['headers']['Accept']);
        self::assertArrayNotHasKey('cookies', $e['request'], 'cookies are never sent');
        self::assertArrayNotHasKey('data', $e['request'], 'request bodies are never sent');
        self::assertSame('failed for password=[Filtered]', $e['exception']['values'][0]['value']);
    }

    public function testRequestContextFromGlobalsDropsQueryAndCookies(): void
    {
        // The CLI SAPI never reads $_SERVER as a request; prove the shape with
        // what an integration would set instead.
        $c = $this->client();
        $c->setRequest(['method' => 'GET', 'url' => Scrubber::stripQuery('https://x.ng/a?token=1'), 'headers' => ['Cookie' => 'a=b']]);
        $c->captureMessage('x');
        $c->flush();
        $e = self::envelope(self::$server->requests('/api/')[0])[2];
        self::assertSame('https://x.ng/a', $e['request']['url']);
        self::assertSame('[Filtered]', $e['request']['headers']['Cookie']);
    }

    public function testLogAndSpanAttributesAreScrubbed(): void
    {
        $c = $this->client();
        $c->log('info', 'paid with 4111 1111 1111 1111', ['secret' => 's', 'amount' => 100]);
        $span = $c->startSpan('GET', ['url.full' => 'https://api.paystack.co/x?key=abc', 'token' => 't']);
        $span->end();
        $c->flush();
        $log = self::json(self::$server->requests('/v1/logs')[0])['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        self::assertSame('paid with [Filtered]', $log['body']['stringValue']);
        $la = self::attrs($log['attributes']);
        self::assertSame('[Filtered]', $la['secret']);
        self::assertSame('100', $la['amount']);
        $sp = self::json(self::$server->requests('/v1/traces')[0])['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $sa = self::attrs($sp['attributes']);
        self::assertSame('https://api.paystack.co/x', $sa['url.full']);
        self::assertSame('[Filtered]', $sa['token']);
    }
}
