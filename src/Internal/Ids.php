<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Internal;

/** @internal */
final class Ids
{
    /** n random bytes as lowercase hex. */
    public static function hex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes(max(1, $bytes)));
        } catch (\Throwable) {
            // random_bytes only fails when the OS has no entropy source. An id
            // that is merely unlikely to collide beats an SDK that throws.
            $out = '';
            for ($i = 0; $i < $bytes; $i++) {
                $out .= sprintf('%02x', mt_rand(0, 255));
            }

            return $out;
        }
    }

    public static function eventId(): string
    {
        return self::hex(16);
    }

    public static function traceId(): string
    {
        return self::hex(16);
    }

    public static function spanId(): string
    {
        return self::hex(8);
    }
}
