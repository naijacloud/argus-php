<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Internal;

/**
 * Privacy defaults (spec section 7). Argus applies the same rules again on
 * arrival; the SDK does not rely on that.
 *
 * @internal
 */
final class Scrubber
{
    public const FILTERED = '[Filtered]';

    private const CONTAINS = [
        'password', 'passwd', 'secret', 'token', 'apikey', 'accesskey', 'authorization',
        'credential', 'cookie', 'privatekey', 'cardnumber', 'creditcard', 'bvn', 'cvv', 'cvc',
        'sessionid', 'sessiontoken', 'signature',
    ];

    private const EXACT = ['auth', 'pwd', 'pin', 'otp', 'session', 'card', 'sid'];

    private const CARD = '/\b(?:\d[ -]?){12,18}\d\b/';
    private const BEARER = '/\b(bearer|basic|token)\s+[A-Za-z0-9._~+\/=-]{8,}/i';
    private const JWT = '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\b/';
    private const KV = '/\b([a-z0-9_-]*(?:password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|credential|bvn|cvv)[a-z0-9_-]*)(["\']?\s*[:=]\s*)(["\']?)([^"\'\s,;&})]+)/i';

    /** Whether a field name's value should be filtered. */
    public static function sensitiveKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }
        $n = str_replace(['-', '_', ' '], '', strtolower($key));
        if (in_array($n, self::EXACT, true)) {
            return true;
        }
        foreach (self::CONTAINS as $needle) {
            if (str_contains($n, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Filters card numbers, credentials, JWTs and sensitive key=value pairs inside a string. */
    public static function string(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $out = preg_replace_callback(self::CARD, static function (array $m): string {
            $digits = str_replace([' ', '-'], '', $m[0]);
            $len = strlen($digits);

            return $len >= 13 && $len <= 19 && self::luhn($digits) ? self::FILTERED : $m[0];
        }, $s);
        $out = preg_replace_callback(self::BEARER, static fn (array $m): string => $m[1] . ' ' . self::FILTERED, $out ?? $s);
        $out = preg_replace(self::JWT, self::FILTERED, $out ?? $s);
        $out = preg_replace_callback(self::KV, static fn (array $m): string => $m[1] . $m[2] . $m[3] . self::FILTERED, $out ?? $s);

        // preg_* return null on invalid UTF-8 or a backtrack limit. Returning the
        // raw string would leak what the scrubber could not read, so filter it all.
        return $out ?? self::FILTERED;
    }

    /**
     * Deep-scrubs a JSON-like value. Objects are flattened to arrays; depth
     * beyond 8 is cut so a cyclic or enormous structure cannot stall a request.
     */
    public static function value(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($depth > 8) {
            return '[...]';
        }
        if ($value instanceof \JsonSerializable) {
            try {
                $value = $value->jsonSerialize();
            } catch (\Throwable) {
                return '[unserializable]';
            }
            if (!is_array($value) && !is_object($value)) {
                return self::value($value, $depth + 1);
            }
        }
        if ($value instanceof \Stringable && !is_iterable($value)) {
            return self::string((string) $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_RFC3339_EXTENDED);
        }
        if ($value instanceof \Throwable) {
            return get_class($value) . ': ' . self::string($value->getMessage());
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            // resources, closures
            return '[' . get_debug_type($value) . ']';
        }
        $out = [];
        $isList = array_is_list($value);
        foreach ($value as $k => $v) {
            $out[$k] = !$isList && self::sensitiveKey($k) && $v !== null && $v !== ''
                ? self::FILTERED
                : self::value($v, $depth + 1);
        }

        return $out;
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    public static function array(array $value): array
    {
        $out = self::value($value);

        return is_array($out) ? $out : [];
    }

    /** Removes the query string and fragment from a URL, keeping it readable. */
    public static function stripQuery(string $url): string
    {
        $cut = strcspn($url, '?#');

        return substr($url, 0, $cut);
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = ord($digits[$i]) - 48;
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = !$alt;
        }

        return $sum % 10 === 0;
    }
}
