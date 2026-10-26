<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

use NaijaCloud\Argus\Internal\ErrorHandlers;

/**
 * The static API. Each method talks to the client init() made; before init,
 * or without a DSN, they do nothing and return the neutral value.
 */
final class Argus
{
    private static ?Client $client = null;

    /**
     * Starts Argus. Reads ARGUS_DSN (then SENTRY_DSN) when no `dsn` is given;
     * without either it is a no-op that never throws.
     *
     * Installs the uncaught-exception and error handlers (keeping any already
     * set) unless `register_handlers` is false, and flushes at shutdown after
     * the response has been sent.
     *
     * @param array<string,mixed> $options
     */
    public static function init(array $options = []): Client
    {
        $client = new Client($options);
        self::setClient($client);
        if ($client->isEnabled()) {
            if ($client->options->registerHandlers) {
                ErrorHandlers::installHandlers();
            }
            if ($client->options->registerShutdown) {
                ErrorHandlers::installShutdown();
            }
        }

        return $client;
    }

    /** Makes $client the one the static API uses (integrations, tests). */
    public static function setClient(?Client $client): void
    {
        self::$client = $client;
        if ($client !== null) {
            ErrorHandlers::bind($client);
        }
    }

    public static function getClient(): ?Client
    {
        return self::$client;
    }

    public static function isEnabled(): bool
    {
        return self::$client?->isEnabled() ?? false;
    }

    // ---- errors ----

    /** @param array<string,mixed> $context level, tags, extra, user, fingerprint, transaction */
    public static function captureException(\Throwable $error, array $context = []): string
    {
        return self::$client?->captureException($error, $context) ?? '';
    }

    /** @param array<string,mixed> $context */
    public static function captureMessage(string $message, string $level = 'info', array $context = []): string
    {
        return self::$client?->captureMessage($message, $level, $context) ?? '';
    }

    /** @param array{id?:string|int,email?:string,username?:string,ip_address?:string}|null $user */
    public static function setUser(?array $user): void
    {
        self::$client?->setUser($user);
    }

    public static function setTag(string $key, string|int|float|bool $value): void
    {
        self::$client?->setTag($key, $value);
    }

    /** @param array<string,string|int|float|bool> $tags */
    public static function setTags(array $tags): void
    {
        self::$client?->setTags($tags);
    }

    /** @param array<string,mixed>|null $context */
    public static function setContext(string $name, ?array $context): void
    {
        self::$client?->setContext($name, $context);
    }

    public static function setExtra(string $key, mixed $value): void
    {
        self::$client?->setExtra($key, $value);
    }

    /** @param array<string,mixed> $crumb type, category, message, level, data */
    public static function addBreadcrumb(array $crumb): void
    {
        self::$client?->addBreadcrumb($crumb);
    }

    public static function clearScope(): void
    {
        self::$client?->clearScope();
    }

    // ---- logs, metrics, traces ----

    /** Logs for Argus Logs: Argus::logs()->info('order placed', ['id' => 81]). */
    public static function logs(): Logs
    {
        return new Logs(self::$client);
    }

    /** Custom metrics: Argus::metrics()->count('orders.placed'). */
    public static function metrics(): Metrics
    {
        return new Metrics(self::$client);
    }

    /**
     * Runs $fn inside a span. Without a client $fn still runs, with a span
     * that records nothing.
     *
     * @template T
     * @param callable(Span):T $fn
     * @param array<string,mixed> $attributes
     * @return T
     */
    public static function trace(string $name, callable $fn, array $attributes = []): mixed
    {
        if (self::$client === null) {
            return $fn(new Span(null, $name));
        }

        return self::$client->trace($name, $fn, $attributes);
    }

    /**
     * Starts a span you end() yourself.
     *
     * @param array<string,mixed> $attributes
     */
    public static function startSpan(string $name, array $attributes = [], int $kind = Span::KIND_INTERNAL, bool $activate = false): Span
    {
        return self::$client?->startSpan($name, $attributes, $kind, $activate) ?? new Span(null, $name, $kind);
    }

    /** The traceparent header for an outgoing HTTP request, or null. */
    public static function traceparent(): ?string
    {
        return self::$client?->traceparent();
    }

    public static function continueTrace(?string $traceparent): void
    {
        self::$client?->continueTrace($traceparent);
    }

    // ---- crons, flags ----

    public static function cron(): Cron
    {
        return new Cron(self::$client);
    }

    public static function flags(): Flags
    {
        return new Flags(self::$client);
    }

    // ---- lifecycle ----

    /** Sends what is queued. True when it all went out within the timeout. */
    public static function flush(?int $timeoutMs = null): bool
    {
        return self::$client?->flush($timeoutMs) ?? true;
    }

    /** Flushes and stops the SDK. */
    public static function close(?int $timeoutMs = null): bool
    {
        $client = self::$client;
        self::$client = null;

        return $client?->close($timeoutMs) ?? true;
    }
}
