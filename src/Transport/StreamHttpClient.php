<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

/**
 * The fallback when ext-curl is missing (some shared hosts and slim images):
 * PHP's own http:// and https:// stream wrappers. TLS verification stays at
 * PHP's default, which is on.
 */
final class StreamHttpClient implements HttpClient
{
    public function post(string $url, string $body, array $headers, float $timeout): HttpResponse
    {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }
        $headerLines .= 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerLines,
                'content' => $body,
                'timeout' => max(0.1, $timeout),
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
        ]);

        $error = null;
        set_error_handler(static function (int $_no, string $msg) use (&$error): bool {
            $error = $msg;

            return true;
        });
        $out = '';
        $raw = null;
        try {
            // fopen + wrapper_data rather than $http_response_header: before
            // PHP 8.4 that variable is only written when it appears literally
            // in the calling function, which is too fragile to rely on.
            $fp = fopen($url, 'rb', false, $context);
            if ($fp !== false) {
                $meta = stream_get_meta_data($fp);
                $raw = $meta['wrapper_data'] ?? null;
                $read = stream_get_contents($fp);
                $out = $read === false ? '' : $read;
                fclose($fp);
            }
        } finally {
            restore_error_handler();
        }
        if (!is_array($raw) || $raw === []) {
            throw new NetworkException($error ?? 'request failed');
        }
        [$status, $parsed] = HttpResponse::parseHeaderLines(array_values(array_map('strval', $raw)));

        return new HttpResponse($status, $parsed, $out);
    }
}
