<?php

declare(strict_types=1);

/**
 * Contract test against a real Argus (staging or prod), not a stand-in.
 *
 *   ARGUS_CONTRACT_DSN=<dsn of a test source> composer test:contract
 *
 * Sends an error, a log, a span, a metric, a cron check-in and a flag
 * evaluation, and checks Argus answered 2xx to each. When ARGUS_CONTRACT_API
 * and ARGUS_CONTRACT_TOKEN are set it also waits for the error to show up as
 * an issue. The cron check-in expects a monitor with slug `argus-php-contract`;
 * a 404 is reported, not failed, when it is missing.
 */

use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Span;
use NaijaCloud\Argus\Transport\CurlHttpClient;
use NaijaCloud\Argus\Transport\HttpClient;
use NaijaCloud\Argus\Transport\HttpResponse;
use NaijaCloud\Argus\Transport\StreamHttpClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dsn = getenv('ARGUS_CONTRACT_DSN');
if (!is_string($dsn) || $dsn === '') {
    fwrite(STDERR, "set ARGUS_CONTRACT_DSN to a test source DSN\n");
    exit(2);
}

/** Records every status Argus answers with. */
final class RecordingClient implements HttpClient
{
    /** @var list<array{path:string,status:int,body:string}> */
    public array $seen = [];

    public function __construct(private readonly HttpClient $inner)
    {
    }

    public function post(string $url, string $body, array $headers, float $timeout): HttpResponse
    {
        $res = $this->inner->post($url, $body, $headers, $timeout);
        $this->seen[] = ['path' => (string) parse_url($url, PHP_URL_PATH), 'status' => $res->status, 'body' => substr($res->body, 0, 200)];

        return $res;
    }
}

$http = new RecordingClient(getenv('ARGUS_CONTRACT_STREAMS') === '1' ? new StreamHttpClient() : new CurlHttpClient());
Argus::init([
    'dsn' => $dsn,
    'release' => 'argus-php@contract',
    'environment' => 'contract',
    'service_name' => 'argus-php-contract',
    'http_client' => $http,
    'register_handlers' => false,
    'debug' => getenv('ARGUS_DEBUG') === '1',
]);

$marker = 'argus-php contract ' . bin2hex(random_bytes(4));
Argus::trace('contract.run', static function (Span $span) use ($marker): void {
    $span->setAttribute('http.request.method', 'GET')->setAttribute('url.full', 'https://example.com/contract?token=x');
    try {
        json_decode('{not json', false, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        Argus::captureException(new \RuntimeException($marker, 0, $e), ['extra' => ['password' => 'hunter2']]);
    }
    Argus::logs()->info('contract log line', ['marker' => $marker]);
});
Argus::metrics()->count('argus_php_contract_runs', 1);
Argus::metrics()->distribution('argus_php_contract_ms', 12.5, [], 'ms');
Argus::cron()->checkIn('argus-php-contract', 'ok');
$flag = Argus::flags()->isOn('argus-php-contract-flag', 'contract-user', false);
$ok = Argus::close(10_000);

$failed = false;
$by = static fn (string $p): array => array_values(array_filter($http->seen, static fn ($s) => str_starts_with($s['path'], $p)));
foreach (['/api/' => 'errors (envelope)', '/v1/logs' => 'logs', '/v1/traces' => 'traces', '/v1/metrics' => 'metrics', '/v1/flags/evaluate' => 'flags'] as $path => $label) {
    $got = $by($path);
    $statuses = implode(',', array_map(static fn ($s) => $s['status'], $got));
    $good = $got !== [] && array_reduce($got, static fn ($c, $s) => $c && $s['status'] >= 200 && $s['status'] < 300, true);
    echo ($good ? 'ok   ' : 'FAIL ') . str_pad($label, 18) . ' ' . $path . ' ' . ($statuses === '' ? '(nothing sent)' : $statuses) . ($good ? '' : '  ' . ($got[0]['body'] ?? '')) . "\n";
    $failed = $failed || !$good;
}
$cron = $by('/v1/cron/');
$cronStatus = implode(',', array_map(static fn ($s) => $s['status'], $cron));
if ($cron !== [] && $cron[0]['status'] === 404) {
    echo "note cron check-in      /v1/cron/argus-php-contract 404 (create a cron monitor with slug argus-php-contract to check this)\n";
} else {
    $good = $cron !== [] && $cron[0]['status'] >= 200 && $cron[0]['status'] < 300;
    echo ($good ? 'ok   ' : 'FAIL ') . "cron check-in      /v1/cron/argus-php-contract {$cronStatus}\n";
    $failed = $failed || !$good;
}
echo 'ok   flag value         argus-php-contract-flag = ' . ($flag ? 'on' : 'off') . "\n";
if (!$ok) {
    echo "FAIL flush timed out\n";
    $failed = true;
}

$api = getenv('ARGUS_CONTRACT_API');
$token = getenv('ARGUS_CONTRACT_TOKEN');
if (!$failed && is_string($api) && $api !== '' && is_string($token) && $token !== '') {
    $found = null;
    for ($i = 0; $i < 30 && $found === null; $i++) {
        sleep(2);
        $ctx = stream_context_create(['http' => ['header' => "Authorization: Bearer {$token}\r\n", 'ignore_errors' => true, 'timeout' => 10]]);
        $raw = @file_get_contents($api . '/v1/issues?q=' . rawurlencode($marker) . '&limit=5', false, $ctx);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        foreach ((is_array($data) ? ($data['issues'] ?? []) : []) as $issue) {
            if (str_contains((string) ($issue['title'] ?? ''), $marker)) {
                $found = $issue;
            }
        }
    }
    if ($found === null) {
        echo "FAIL the error \"{$marker}\" never showed up as an issue\n";
        $failed = true;
    } else {
        echo "ok   issue {$found['id']} grouped from the Argus SDK\n";
    }
}

echo $failed ? "contract FAILED\n" : "contract passed ({$marker})\n";
exit($failed ? 1 : 0);
