<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

/** The default client when ext-curl is loaded. */
final class CurlHttpClient implements HttpClient
{
    /** @var \CurlHandle|null reused so keep-alive saves a TLS handshake per flush */
    private ?\CurlHandle $handle = null;

    public function post(string $url, string $body, array $headers, float $timeout): HttpResponse
    {
        if ($url === '') {
            throw new NetworkException('no URL');
        }
        $ch = $this->handle ??= curl_init();
        if ($ch === false) {
            throw new NetworkException('curl_init failed');
        }
        curl_reset($ch);
        $lines = [];
        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = $name . ': ' . $value;
        }
        // An empty Expect stops curl waiting a second for "100 Continue" on
        // bodies over 1 KB, which would eat half the shutdown budget.
        $headerList[] = 'Expect:';
        $ms = max(1, (int) round($timeout * 1000));
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $ms,
            CURLOPT_CONNECTTIMEOUT_MS => min($ms, 1500),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$lines): int {
                $lines[] = $line;

                return strlen($line);
            },
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            $this->handle = null;

            throw new NetworkException($err !== '' ? $err : 'request failed');
        }
        [$status, $parsed] = HttpResponse::parseHeaderLines($lines);
        if ($status === 0) {
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }

        return new HttpResponse($status, $parsed, is_string($out) ? $out : '');
    }
}
