<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

use NaijaCloud\Argus\Transport\HttpClient;

/**
 * The resolved configuration. Built from the array given to init(); unknown
 * keys are ignored and a value of the wrong type falls back to the default, so
 * a bad config line never stops the app.
 *
 * Order for the three variables the platform injects (spec section 1): the
 * option, then ARGUS_*, then SENTRY_*.
 */
final class Options
{
    public readonly ?string $dsn;
    public readonly ?string $release;
    public readonly string $environment;
    public readonly string $serviceName;
    public readonly ?string $serverName;
    public readonly float $sampleRate;
    public readonly float $tracesSampleRate;
    public readonly bool $sendDefaultPii;
    public readonly int $maxBreadcrumbs;
    /** @var (\Closure(array<string,mixed>):(array<string,mixed>|null))|null */
    public readonly ?\Closure $beforeSend;
    /** @var (\Closure(array<string,mixed>):(array<string,mixed>|null))|null */
    public readonly ?\Closure $beforeBreadcrumb;
    public readonly bool $debug;
    public readonly bool $enabled;
    public readonly ?HttpClient $httpClient;
    /** Shutdown flush budget, milliseconds. */
    public readonly int $flushTimeoutMs;
    /** How long a flag evaluation may take before the fallback is used, milliseconds. */
    public readonly int $flagsTimeoutMs;
    public readonly bool $registerHandlers;
    public readonly bool $registerShutdown;
    public readonly int $errorTypes;
    public readonly bool $finishRequest;
    public readonly ?string $projectRoot;
    /** @var list<string> */
    public readonly array $inAppExclude;
    /** @var list<class-string> */
    public readonly array $ignoreExceptions;
    public readonly bool $contextLines;
    public readonly bool $captureRequest;
    /** @var array<string,string> */
    public readonly array $tags;
    /** @var (\Closure(string):void)|null */
    public readonly ?\Closure $logger;
    /** @var (\Closure(float):void)|null */
    public readonly ?\Closure $sleeper;

    /** @param array<string,mixed> $o */
    public function __construct(array $o = [])
    {
        $this->dsn = self::firstString($o['dsn'] ?? null, self::env('ARGUS_DSN'), self::env('SENTRY_DSN'));
        $this->release = self::firstString($o['release'] ?? null, self::env('ARGUS_RELEASE'), self::env('SENTRY_RELEASE'));
        $this->environment = self::firstString($o['environment'] ?? null, self::env('ARGUS_ENVIRONMENT'), self::env('SENTRY_ENVIRONMENT')) ?? 'production';
        $this->projectRoot = self::firstString($o['project_root'] ?? null, self::guessRoot());
        $this->serviceName = self::firstString($o['service_name'] ?? null, self::env('ARGUS_SERVICE_NAME'), self::composerName($this->projectRoot)) ?? 'app';
        $host = function_exists('gethostname') ? gethostname() : false;
        $this->serverName = self::firstString($o['server_name'] ?? null, $host === false ? null : $host);
        $this->sampleRate = self::rate($o['sample_rate'] ?? null);
        $this->tracesSampleRate = self::rate($o['traces_sample_rate'] ?? null);
        $this->sendDefaultPii = ($o['send_default_pii'] ?? false) === true;
        $this->maxBreadcrumbs = is_int($o['max_breadcrumbs'] ?? null) ? max(0, min(100, $o['max_breadcrumbs'])) : 100;
        $this->beforeSend = is_callable($o['before_send'] ?? null) ? \Closure::fromCallable($o['before_send']) : null;
        $this->beforeBreadcrumb = is_callable($o['before_breadcrumb'] ?? null) ? \Closure::fromCallable($o['before_breadcrumb']) : null;
        $debugEnv = self::env('ARGUS_DEBUG');
        $this->debug = ($o['debug'] ?? false) === true || ($debugEnv !== null && in_array(strtolower($debugEnv), ['1', 'true', 'yes', 'on'], true));
        $this->enabled = ($o['enabled'] ?? true) !== false;
        $this->httpClient = ($o['http_client'] ?? null) instanceof HttpClient ? $o['http_client'] : null;
        $this->flushTimeoutMs = is_int($o['flush_timeout_ms'] ?? null) ? max(0, $o['flush_timeout_ms']) : 2000;
        $this->flagsTimeoutMs = is_int($o['flags_timeout_ms'] ?? null) ? max(1, $o['flags_timeout_ms']) : 2000;
        $this->registerHandlers = ($o['register_handlers'] ?? true) !== false;
        $this->registerShutdown = ($o['register_shutdown'] ?? true) !== false;
        $this->errorTypes = is_int($o['error_types'] ?? null)
            ? $o['error_types']
            : E_ALL & ~(E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE);
        $this->finishRequest = ($o['finish_request'] ?? true) !== false;
        $this->inAppExclude = is_array($o['in_app_exclude'] ?? null)
            ? array_values(array_filter($o['in_app_exclude'], 'is_string'))
            : ['/vendor/'];
        $this->ignoreExceptions = is_array($o['ignore_exceptions'] ?? null)
            ? array_values(array_filter(
                $o['ignore_exceptions'],
                static fn ($c): bool => is_string($c) && (class_exists($c) || interface_exists($c)),
            ))
            : [];
        $this->contextLines = ($o['context_lines'] ?? true) !== false;
        $this->captureRequest = ($o['capture_request'] ?? true) !== false;
        $tags = [];
        if (is_array($o['tags'] ?? null)) {
            foreach ($o['tags'] as $k => $v) {
                if (is_scalar($v)) {
                    $tags[(string) $k] = (string) $v;
                }
            }
        }
        $this->tags = $tags;
        $this->logger = is_callable($o['logger'] ?? null) ? \Closure::fromCallable($o['logger']) : null;
        $this->sleeper = is_callable($o['sleeper'] ?? null) ? \Closure::fromCallable($o['sleeper']) : null;
    }

    private static function env(string $name): ?string
    {
        // $_SERVER/$_ENV first: phpdotenv and FPM pools set those without
        // calling putenv(), so getenv() alone would miss a .env value.
        foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        $v = getenv($name);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function firstString(mixed ...$values): ?string
    {
        foreach ($values as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    private static function rate(mixed $v): float
    {
        if (!is_int($v) && !is_float($v)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $v));
    }

    /** The directory holding vendor/, found from where Composer installed us. */
    private static function guessRoot(): ?string
    {
        $dir = __DIR__;
        $marker = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $pos = strrpos($dir, $marker);
        if ($pos !== false) {
            return substr($dir, 0, $pos);
        }
        $cwd = getcwd();

        return $cwd === false ? null : $cwd;
    }

    /** service.name defaults to the app's composer.json name, like JS uses package.json. */
    private static function composerName(?string $root): ?string
    {
        if ($root === null) {
            return null;
        }
        $file = $root . DIRECTORY_SEPARATOR . 'composer.json';
        if (!@is_file($file)) {
            return null;
        }
        $json = @file_get_contents($file, false, null, 0, 262_144);
        $data = is_string($json) ? json_decode($json, true) : null;
        $name = is_array($data) ? ($data['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
