<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Transport;

final class HttpResponse
{
    /** @var array<string,string> lowercased names */
    public readonly array $headers;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = $value;
        }
        $this->headers = $lower;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Parses raw "Name: value" header lines (curl's header callback, or a
     * stream's response headers), keeping the last response's headers only.
     *
     * @param list<string> $lines
     * @return array{0:int,1:array<string,string>}
     */
    public static function parseHeaderLines(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            $line = rtrim($line, "\r\n");
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // A 100 Continue or a proxy CONNECT puts more than one status
                // line in the stream; only the last response counts.
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        return [$status, $headers];
    }
}
