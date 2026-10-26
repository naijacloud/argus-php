<?php

declare(strict_types=1);

namespace NaijaCloud\Argus\Internal;

use NaijaCloud\Argus\Client;

/**
 * The global hooks plain PHP needs: uncaught exceptions, PHP errors, fatal
 * errors at shutdown, and the shutdown flush. Every previous handler is kept
 * and called, so installing Argus changes nothing about how the app already
 * behaves.
 *
 * @internal
 */
final class ErrorHandlers
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    private static ?Client $client = null;
    private static bool $handlersInstalled = false;
    private static bool $shutdownInstalled = false;
    private static bool $uncaughtSeen = false;
    /** @var callable|null */
    private static $previousException = null;
    /** @var callable|null */
    private static $previousError = null;
    /** @var array<string,true> */
    private static array $seenErrors = [];
    /** Freed at shutdown so an out-of-memory fatal still has room to be reported. */
    private static ?string $reserve = null;

    public static function bind(Client $client): void
    {
        self::$client = $client;
    }

    public static function installHandlers(): void
    {
        if (self::$handlersInstalled) {
            return;
        }
        self::$handlersInstalled = true;
        self::$previousException = set_exception_handler([self::class, 'handleException']);
        self::$previousError = set_error_handler([self::class, 'handleError']);
        self::$reserve = str_repeat('x', 32 * 1024);
    }

    public static function installShutdown(): void
    {
        if (self::$shutdownInstalled) {
            return;
        }
        self::$shutdownInstalled = true;
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function handleException(\Throwable $e): void
    {
        self::$uncaughtSeen = true;
        $client = self::$client;
        if ($client !== null && !$client->wasCaptured($e)) {
            $client->captureException($e, ['level' => 'fatal', 'tags' => ['handled' => 'no']]);
        }
        $previous = self::$previousException;
        if ($previous !== null) {
            $previous($e);

            return;
        }
        // No handler before ours: rethrow, which PHP reports as its own
        // "PHP Fatal error: Uncaught ..." with exit code 255, exactly as without
        // Argus. (Restoring the handler first would re-enter this one.) The
        // shutdown flush still runs.
        throw $e;
    }

    public static function handleError(int $type, string $message, string $file = '', int $line = 0): bool
    {
        try {
            $client = self::$client;
            // error_reporting() excludes the type when the call was silenced
            // with @ (or the app turned the type off); respect both.
            if ($client !== null && (error_reporting() & $type) !== 0) {
                if (($client->options->errorTypes & $type) !== 0) {
                    $key = $type . '|' . $file . '|' . $line . '|' . $message;
                    if (!isset(self::$seenErrors[$key]) && count(self::$seenErrors) < 100) {
                        self::$seenErrors[$key] = true;
                        $client->captureException(
                            new \ErrorException(self::typeName($type) . ': ' . $message, 0, $type, $file, $line),
                            ['level' => self::level($type)],
                        );
                    }
                } else {
                    $client->addBreadcrumb([
                        'type' => 'error',
                        'category' => 'php',
                        'level' => self::level($type),
                        'message' => self::typeName($type) . ': ' . $message,
                        'data' => ['file' => $file, 'line' => $line],
                    ]);
                }
            }
        } catch (\Throwable) {
            // the handler must never be the thing that breaks
        }
        $previous = self::$previousError;
        if ($previous !== null) {
            return (bool) $previous($type, $message, $file, $line);
        }

        return false; // let PHP's own handler log or display it as usual
    }

    public static function onShutdown(): void
    {
        if (self::$reserve !== null) {
            self::$reserve = null;
        }
        $client = self::$client;
        if ($client === null) {
            return;
        }
        try {
            $err = error_get_last();
            if (self::$handlersInstalled && $err !== null && ($err['type'] & self::FATAL) !== 0
                && !(self::$uncaughtSeen && str_starts_with($err['message'], 'Uncaught '))) {
                $client->captureException(
                    new \ErrorException(self::typeName($err['type']) . ': ' . $err['message'], 0, $err['type'], $err['file'], $err['line']),
                    ['level' => 'fatal', 'tags' => ['handled' => 'no']],
                );
            }
        } catch (\Throwable) {
        }
        // Flush from a shutdown function registered now, so it runs after any
        // the app registered later (which may still log).
        register_shutdown_function([self::class, 'finalFlush']);
    }

    public static function finalFlush(): void
    {
        $client = self::$client;
        if ($client === null || !$client->isEnabled() || !$client->hasPending()) {
            return;
        }
        try {
            if ($client->options->finishRequest) {
                // Hand the response to the web server first, so the visitor
                // never waits on Argus. Both functions end output buffering.
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
            }
            $client->flush();
        } catch (\Throwable) {
        }
    }

    private static function level(int $type): string
    {
        return match ($type) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE => 'fatal',
            E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
            E_NOTICE, E_USER_NOTICE => 'info',
            default => 'debug',
        };
    }

    private static function typeName(int $type): string
    {
        return match ($type) {
            E_ERROR => 'Fatal error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core error',
            E_CORE_WARNING => 'Core warning',
            E_COMPILE_ERROR => 'Compile error',
            E_COMPILE_WARNING => 'Compile warning',
            E_USER_ERROR => 'User error',
            E_USER_WARNING => 'User warning',
            E_USER_NOTICE => 'User notice',
            E_RECOVERABLE_ERROR => 'Recoverable error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User deprecated',
            default => 'Error',
        };
    }

    /** @internal tests */
    public static function reset(): void
    {
        if (self::$handlersInstalled) {
            restore_error_handler();
            restore_exception_handler();
        }
        self::$handlersInstalled = false;
        self::$client = null;
        self::$previousError = null;
        self::$previousException = null;
        self::$seenErrors = [];
        self::$uncaughtSeen = false;
    }
}
