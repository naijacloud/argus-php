<?php

declare(strict_types=1);

/*
 * Function-style entry points, for apps that prefer
 * `\NaijaCloud\Argus\init([...])` to the static class. Each forwards to Argus.
 */

namespace NaijaCloud\Argus;

if (!function_exists(__NAMESPACE__ . '\init')) {
    /** @param array<string,mixed> $options */
    function init(array $options = []): Client
    {
        return Argus::init($options);
    }

    /** @param array<string,mixed> $context */
    function captureException(\Throwable $error, array $context = []): string
    {
        return Argus::captureException($error, $context);
    }

    /** @param array<string,mixed> $context */
    function captureMessage(string $message, string $level = 'info', array $context = []): string
    {
        return Argus::captureMessage($message, $level, $context);
    }

    function flush(?int $timeoutMs = null): bool
    {
        return Argus::flush($timeoutMs);
    }
}
