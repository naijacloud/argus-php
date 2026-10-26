<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Log;

use NaijaCloud\Argus\Client;

/**
 * What PsrLogger and MonologHandler share: PSR-3 interpolation, context to
 * attributes, and the exception-to-error rule.
 *
 * @internal
 */
final class LogRecordWriter
{
    private const ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    /** @param array<array-key,mixed> $context */
    public static function write(Client $client, string $level, string $message, array $context, bool $captureExceptions, ?string $channel = null): void
    {
        try {
            $exception = $context['exception'] ?? null;
            unset($context['exception']);
            $message = self::interpolate($message, $context);
            $attributes = [];
            foreach ($context as $k => $v) {
                $attributes[is_int($k) ? 'context.' . $k : (string) $k] = $v;
            }
            if ($channel !== null) {
                $attributes['log.channel'] = $channel;
            }
            if ($exception instanceof \Throwable) {
                $attributes['exception.type'] = get_class($exception);
                $attributes['exception.message'] = $exception->getMessage();
                if ($captureExceptions && in_array(strtolower($level), self::ERROR_LEVELS, true)) {
                    $client->captureException($exception, ['level' => strtolower($level) === 'error' ? 'error' : 'fatal']);
                }
            }
            $client->log($level, $message, $attributes);
        } catch (\Throwable) {
            // a logger must never be the reason a request fails
        }
    }

    /** @param array<array-key,mixed> $context */
    public static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($context as $k => $v) {
            if (is_scalar($v) || $v === null || $v instanceof \Stringable) {
                $replace['{' . $k . '}'] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            } elseif ($v instanceof \DateTimeInterface) {
                $replace['{' . $k . '}'] = $v->format(DATE_RFC3339);
            }
        }

        return strtr($message, $replace);
    }
}
