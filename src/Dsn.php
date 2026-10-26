<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

/**
 * A parsed DSN: `https://<publicKey>@<host>/<projectId>` (spec section 1).
 *
 * The public key is the only credential the SDK holds. It is safe to expose,
 * but it is still kept out of debug output so a log line cannot be mistaken
 * for a leak.
 */
final class Dsn
{
    private function __construct(
        public readonly string $publicKey,
        public readonly string $projectId,
        /** `https://argus-in.naijacloud.com`, plus any path prefix before the project id. */
        public readonly string $origin,
        public readonly string $raw,
    ) {
    }

    /** Parses a DSN, or returns null for anything that is not one. Never throws. */
    public static function parse(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (($scheme !== 'https' && $scheme !== 'http') || !isset($parts['host'], $parts['user'])) {
            return null;
        }
        $key = rawurldecode($parts['user']);
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $s): bool => $s !== ''));
        $project = array_pop($segments);
        if ($key === '' || $project === null || preg_match('/^\d+$/', $project) !== 1) {
            return null;
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $prefix = $segments === [] ? '' : '/' . implode('/', $segments);

        return new self($key, $project, $scheme . '://' . $host . $prefix, $raw);
    }

    /** The error endpoint (spec section 3). */
    public function envelopeUrl(): string
    {
        return $this->origin . '/api/' . $this->projectId . '/envelope/';
    }

    /** Any other ingest endpoint; servers authenticate with a header, not the URL. */
    public function ingestUrl(string $path): string
    {
        return $this->origin . $path;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['origin' => $this->origin, 'projectId' => $this->projectId, 'publicKey' => '[Filtered]'];
    }
}
