<?php

declare(strict_types=1);

/**
 * The mock Argus ingest, served by `php -S`. The scenario is the DSN's path
 * prefix (`/s/<scenario>/<project>`), so no test reaches inside the SDK to
 * make the server behave.
 */

$stateDir = getenv('ARGUS_MOCK_STATE') ?: sys_get_temp_dir();
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scenario = 'ok';
if (preg_match('#^/s/([^/]+)(/.*)$#', $path, $m) === 1) {
    $scenario = $m[1];
    $path = $m[2];
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (is_string($name) && str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
$raw = (string) file_get_contents('php://input');
$body = $raw;
if (($headers['content-encoding'] ?? '') === 'gzip') {
    $body = (string) gzdecode($raw);
}

file_put_contents($stateDir . '/requests.jsonl', json_encode([
    'scenario' => $scenario,
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => $body,
    'raw_length' => strlen($raw),
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);

$hit = static function () use ($stateDir, $scenario, $path): int {
    $f = $stateDir . '/count-' . md5($scenario . $path);
    $n = (is_file($f) ? (int) file_get_contents($f) : 0) + 1;
    file_put_contents($f, (string) $n, LOCK_EX);

    return $n;
};

$ok = static function () use ($path): void {
    header('Content-Type: application/json');
    if (str_starts_with($path, '/api/')) {
        echo json_encode(['id' => '00000000000000000000000000000000']);
    } elseif ($path === '/v1/flags/evaluate') {
        $req = json_decode((string) file_get_contents('php://input'), true);
        echo json_encode([
            'flags' => ['new-checkout' => 'on', 'old-banner' => 'off', 'theme' => 'dark', 'subject-echo' => (string) ($req['subject'] ?? '')],
            'environment' => (string) ($req['environment'] ?? ''),
        ]);
    } elseif (str_starts_with($path, '/v1/cron/missing')) {
        http_response_code(404);
        echo json_encode(['error' => 'no monitor with that slug']);
    } else {
        echo '{}';
    }
};

switch ($scenario) {
    case 'ok':
        $ok();
        break;
    case 'r429':
        http_response_code(429);
        header('Retry-After: 30');
        header('X-Sentry-Rate-Limits: 30:error;default:organization');
        break;
    case 'r401':
        http_response_code(401);
        echo '{"error":"unknown key"}';
        break;
    case 'r403':
        http_response_code(403);
        break;
    case 'r400':
        http_response_code(400);
        echo '{"error":"malformed"}';
        break;
    case 'r500':
        http_response_code(500);
        break;
    case 'flaky':
        // fails twice, then works: the backoff path
        if ($hit() <= 2) {
            http_response_code(503);
            break;
        }
        $ok();
        break;
    case 'dropped':
        header('X-Argus-Dropped-Series: 3');
        $ok();
        break;
    case 'slow':
        // longer than any timeout the tests set, short enough not to stall the
        // single-process server for the next test
        usleep(1_200_000);
        $ok();
        break;
    case 'html':
        http_response_code(502);
        header('Content-Type: text/html');
        echo '<html>bad gateway</html>';
        break;
    default:
        http_response_code(400);
        echo 'unknown mock scenario';
}

return true;
