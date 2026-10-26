<?php

declare(strict_types=1);

namespace NaijaCloud\Argus;

use NaijaCloud\Argus\Internal\Ids;
use NaijaCloud\Argus\Internal\Otlp;
use NaijaCloud\Argus\Internal\Scrubber;
use NaijaCloud\Argus\Internal\Stacktrace;
use NaijaCloud\Argus\Transport\CurlHttpClient;
use NaijaCloud\Argus\Transport\StreamHttpClient;
use NaijaCloud\Argus\Transport\Transport;

/**
 * One Argus client: the scope (user, tags, breadcrumbs), the buffers for logs,
 * spans and metrics, and the transport. Every public method is safe to call
 * with no DSN, in which case it does nothing, and none of them throws.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const CONTRACT = 'argus.sdk/1';
    public const SDK_NAME = 'argus.php';

    public const LEVELS = ['fatal', 'error', 'warning', 'info', 'debug'];

    private const MAX_LOGS = 1000;
    private const MAX_SPANS = 512;
    private const MAX_SERIES = 2000;
    /** Requests are split below the 1 MB queue cap so one batch never evicts another. */
    private const BATCH_BYTES = 512_000;
    private const LOG_EVERY = 5.0;
    private const METRICS_EVERY = 10.0;
    private const HIST_BOUNDS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    public readonly Options $options;
    public readonly ?Dsn $dsn;
    private Transport $transport;
    private Stacktrace $stacktrace;

    /** @var list<array<string,mixed>> */
    private array $breadcrumbs = [];
    /** @var array<string,mixed>|null */
    private ?array $user = null;
    /** @var array<string,string> */
    private array $tags;
    /** @var array<string,array<string,mixed>> */
    private array $contexts = [];
    /** @var array<string,mixed> */
    private array $extra = [];
    /** @var array<string,mixed>|null */
    private ?array $request = null;
    private ?string $transaction = null;

    /** @var list<Span> */
    private array $activeSpans = [];
    /** @var array{traceId:string,spanId:string,sampled:bool}|null */
    private ?array $remoteParent = null;

    /** @var list<array{time:float,severity:string,body:string,attributes:array<string,mixed>,traceId:?string,spanId:?string}> */
    private array $logBuf = [];
    /** @var list<array<string,mixed>> */
    private array $spanBuf = [];
    /** @var array<string,array{kind:string,name:string,unit:?string,attributes:array<string,mixed>,value:float,count:int,sum:float,min:float,max:float,buckets:list<int>}> */
    private array $metricAgg = [];
    private float $metricStart;
    private float $lastLogFlush;
    private float $lastMetricFlush;

    /** @var \WeakMap<\Throwable,true> */
    private \WeakMap $captured;
    /** @var array<string,array{at:float,flags:array<string,string>}> */
    private array $flagCache = [];
    private bool $closed = false;

    /** @param array<string,mixed>|Options $options */
    public function __construct(array|Options $options = [])
    {
        $this->options = $options instanceof Options ? $options : new Options($options);
        $this->dsn = $this->options->enabled ? Dsn::parse($this->options->dsn) : null;
        if ($this->options->dsn !== null && $this->dsn === null && $this->options->enabled) {
            $this->debug('the DSN is not valid; nothing will be sent');
        }
        $http = $this->options->httpClient
            ?? (function_exists('curl_init') ? new CurlHttpClient() : new StreamHttpClient());
        // A test sleeper replaces real waiting, so the transport's clock must
        // advance by what it "slept" or a backoff would spin until real time
        // caught up.
        $sleep = $now = null;
        if ($this->options->sleeper !== null) {
            $offset = 0.0;
            $sleeper = $this->options->sleeper;
            $sleep = static function (float $s) use (&$offset, $sleeper): void {
                $offset += $s;
                $sleeper($s);
            };
            $now = static function () use (&$offset): float {
                return microtime(true) + $offset;
            };
        }
        $this->transport = new Transport(
            $http,
            $this->options->debug || $this->options->logger !== null ? fn (string $m) => $this->debug($m) : null,
            $sleep,
            $now,
        );
        $this->stacktrace = new Stacktrace($this->options->projectRoot, $this->options->inAppExclude, $this->options->contextLines);
        $this->tags = $this->options->tags;
        $this->captured = new \WeakMap();
        $this->metricStart = $this->lastLogFlush = $this->lastMetricFlush = microtime(true);
    }

    /** Whether a DSN was given and the SDK will send anything. */
    public function isEnabled(): bool
    {
        return $this->dsn !== null && !$this->closed;
    }

    /** @internal */
    public function getTransport(): Transport
    {
        return $this->transport;
    }

    // ---- scope ----

    /** @param array{id?:string|int,email?:string,username?:string,ip_address?:string}|null $user */
    public function setUser(?array $user): void
    {
        $this->user = $user === null ? null : array_map(static fn ($v) => is_scalar($v) ? (string) $v : $v, $user);
    }

    public function setTag(string $key, string|int|float|bool $value): void
    {
        $this->tags[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @param array<string,string|int|float|bool> $tags */
    public function setTags(array $tags): void
    {
        foreach ($tags as $k => $v) {
            $this->setTag((string) $k, $v);
        }
    }

    public function removeTag(string $key): void
    {
        unset($this->tags[$key]);
    }

    /** @param array<string,mixed>|null $context */
    public function setContext(string $name, ?array $context): void
    {
        if ($context === null) {
            unset($this->contexts[$name]);
        } else {
            $this->contexts[$name] = $context;
        }
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->extra[$key] = $value;
    }

    /**
     * The HTTP request the next events belong to. Integrations set this; plain
     * PHP fills it from $_SERVER. Bodies and cookies are never sent.
     *
     * @param array{method?:string,url?:string,headers?:array<string,string>}|null $request
     */
    public function setRequest(?array $request): void
    {
        $this->request = $request;
    }

    /** The route or job events belong to (their culprit in Argus). */
    public function setTransaction(?string $name): void
    {
        $this->transaction = $name;
    }

    /**
     * @param array{timestamp?:float,type?:string,category?:string,message?:string,level?:string,data?:array<string,mixed>} $crumb
     */
    public function addBreadcrumb(array $crumb): void
    {
        if (!$this->isEnabled() || $this->options->maxBreadcrumbs === 0) {
            return;
        }
        $c = ['timestamp' => microtime(true)] + $crumb;
        if ($this->options->beforeBreadcrumb !== null) {
            try {
                $c = ($this->options->beforeBreadcrumb)($c);
            } catch (\Throwable) {
                // keep the crumb
            }
            if (!is_array($c)) {
                return;
            }
        }
        $this->breadcrumbs[] = $c;
        $over = count($this->breadcrumbs) - $this->options->maxBreadcrumbs;
        if ($over > 0) {
            array_splice($this->breadcrumbs, 0, $over);
        }
    }

    /**
     * Forgets user, tags, contexts, extra, breadcrumbs and the request, for
     * long-running workers between units of work. Tags given to init() stay.
     */
    public function clearScope(): void
    {
        $this->breadcrumbs = [];
        $this->user = null;
        $this->tags = $this->options->tags;
        $this->contexts = [];
        $this->extra = [];
        $this->request = null;
        $this->transaction = null;
        $this->remoteParent = null;
    }

    // ---- errors ----

    /**
     * Sends an error. Returns its event id, or '' when nothing was sent. The
     * same Throwable is sent once, however many handlers see it.
     *
     * @param array{level?:string,tags?:array<string,string>,extra?:array<string,mixed>,user?:array<string,mixed>,fingerprint?:list<string>,request?:array<string,mixed>,transaction?:string,contexts?:array<string,mixed>} $context
     */
    public function captureException(\Throwable $error, array $context = []): string
    {
        try {
            if (!$this->isEnabled() || isset($this->captured[$error])) {
                return '';
            }
            $this->captured[$error] = true;
            foreach ($this->options->ignoreExceptions as $class) {
                if ($error instanceof $class) {
                    return '';
                }
            }

            return $this->sendEvent(['exception' => ['values' => $this->stacktrace->exceptionValues($error)]], $context);
        } catch (\Throwable $e) {
            $this->debug('captureException failed: ' . $e->getMessage());

            return '';
        }
    }

    /** Whether this Throwable was already sent (integrations use it to avoid doubles). */
    public function wasCaptured(\Throwable $error): bool
    {
        return isset($this->captured[$error]);
    }

    /**
     * @param array{tags?:array<string,string>,extra?:array<string,mixed>,user?:array<string,mixed>,fingerprint?:list<string>,request?:array<string,mixed>,transaction?:string,contexts?:array<string,mixed>} $context
     */
    public function captureMessage(string $message, string $level = 'info', array $context = []): string
    {
        try {
            if (!$this->isEnabled()) {
                return '';
            }

            return $this->sendEvent(['message' => $message], ['level' => $level] + $context);
        } catch (\Throwable $e) {
            $this->debug('captureMessage failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Sends a Sentry-shaped event as given (after scope, beforeSend and
     * scrubbing). For integrations that build their own.
     *
     * @param array<string,mixed> $event
     */
    public function captureEvent(array $event): string
    {
        try {
            return $this->isEnabled() ? $this->sendEvent($event, []) : '';
        } catch (\Throwable $e) {
            $this->debug('captureEvent failed: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * @param array<string,mixed> $partial
     * @param array<string,mixed> $ctx
     */
    private function sendEvent(array $partial, array $ctx): string
    {
        if ($this->options->sampleRate < 1.0 && (mt_rand() / mt_getrandmax()) >= $this->options->sampleRate) {
            return '';
        }
        $level = is_string($ctx['level'] ?? null) && in_array($ctx['level'], self::LEVELS, true) ? $ctx['level'] : 'error';
        $active = $this->activeSpan();
        $root = $this->activeSpans[0] ?? null;
        $contexts = $this->platformContexts() + $this->contexts;
        if (is_array($ctx['contexts'] ?? null)) {
            $contexts = $ctx['contexts'] + $contexts;
        }
        if ($active !== null) {
            $contexts['trace'] = ['trace_id' => $active->traceId, 'span_id' => $active->spanId];
        }
        $tags = $this->tags;
        if (is_array($ctx['tags'] ?? null)) {
            foreach ($ctx['tags'] as $k => $v) {
                if (is_scalar($v)) {
                    $tags[(string) $k] = (string) $v;
                }
            }
        }
        $extra = $this->extra;
        if (is_array($ctx['extra'] ?? null)) {
            $extra = $ctx['extra'] + $extra;
        }
        $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : $this->user;
        if ($this->options->sendDefaultPii && PHP_SAPI !== 'cli' && is_string($_SERVER['REMOTE_ADDR'] ?? null)) {
            $user = ($user ?? []) + ['ip_address' => $_SERVER['REMOTE_ADDR']];
        }
        $event = [
            'event_id' => Ids::eventId(),
            'timestamp' => microtime(true),
            'platform' => 'php',
            'level' => $level,
            'release' => self::cut($this->options->release, 200),
            'environment' => self::cut($this->options->environment, 64),
            'server_name' => self::cut($this->options->serverName, 200),
            'transaction' => self::cut(is_string($ctx['transaction'] ?? null) ? $ctx['transaction'] : ($this->transaction ?? $root?->name), 300),
            'tags' => array_slice($tags, 0, 50, true),
            'user' => $user,
            'breadcrumbs' => ['values' => $this->breadcrumbs],
            'contexts' => $contexts,
            'extra' => $extra === [] ? null : $extra,
            'request' => is_array($ctx['request'] ?? null) ? $ctx['request'] : ($this->request ?? $this->requestFromGlobals()),
            'fingerprint' => is_array($ctx['fingerprint'] ?? null) ? array_values(array_map('strval', $ctx['fingerprint'])) : null,
        ];
        foreach ($partial as $k => $v) {
            $event[$k] = $v;
        }
        if (is_array($event['user'] ?? null) && !$this->options->sendDefaultPii) {
            unset($event['user']['ip_address']);
        }
        $event = array_filter($event, static fn ($v) => $v !== null && $v !== []);
        if ($this->options->beforeSend !== null) {
            try {
                $changed = ($this->options->beforeSend)($event);
                if ($changed === null) {
                    return '';
                }
                if (is_array($changed)) {
                    $event = $changed;
                }
            } catch (\Throwable $e) {
                $this->debug('beforeSend threw; sending the event unchanged: ' . $e->getMessage());
            }
        }
        $sdk = ['name' => self::SDK_NAME, 'version' => self::VERSION];
        if (isset($event['request']['cookies'])) {
            unset($event['request']['cookies']);
        }
        if (isset($event['request']['data'])) {
            unset($event['request']['data']);
        }
        $clean = self::pruneEmpty(Scrubber::array($event));
        $clean['sdk'] = $sdk;
        $eventId = is_string($clean['event_id'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $clean['event_id']) === 1
            ? $clean['event_id']
            : ($clean['event_id'] = Ids::eventId());
        /** @var Dsn $dsn */
        $dsn = $this->dsn;
        $body = self::json(['event_id' => $eventId, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'dsn' => $dsn->raw, 'sdk' => $sdk]) . "\n"
            . self::json(['type' => 'event', 'content_type' => 'application/json']) . "\n"
            . self::json($clean) . "\n";
        $headers = [
            'Content-Type' => 'application/x-sentry-envelope',
            'User-Agent' => self::SDK_NAME . '/' . self::VERSION,
            'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=' . self::SDK_NAME . '/' . self::VERSION . ', sentry_key=' . $dsn->publicKey,
        ];
        if (strlen($body) > 1024 && function_exists('gzencode')) {
            $gz = gzencode($body, 6);
            if ($gz !== false) {
                $body = $gz;
                $headers['Content-Encoding'] = 'gzip';
            }
        }
        $this->transport->enqueue('errors', $dsn->envelopeUrl(), $body, $headers);

        return $eventId;
    }

    /** @return array<string,array<string,mixed>> */
    private function platformContexts(): array
    {
        return [
            'runtime' => ['name' => 'php', 'version' => PHP_VERSION, 'sapi' => PHP_SAPI],
            'os' => ['name' => PHP_OS_FAMILY],
        ];
    }

    /** @return array<string,mixed>|null */
    private function requestFromGlobals(): ?array
    {
        if (!$this->options->captureRequest || PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || !isset($_SERVER['REQUEST_METHOD'])) {
            return null;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && str_starts_with($k, 'HTTP_') && is_string($v) && $k !== 'HTTP_COOKIE') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
            }
        }

        return [
            'method' => (string) $_SERVER['REQUEST_METHOD'],
            'url' => Scrubber::stripQuery(($https ? 'https' : 'http') . '://' . $host . $uri),
            'headers' => $headers,
        ];
    }

    // ---- logs ----

    /**
     * Records a log line for Argus Logs. $severity is trace, debug, info, warn,
     * error or fatal (PSR-3 names are mapped).
     *
     * @param array<string,mixed> $attributes
     */
    public function log(string $severity, string|\Stringable $message, array $attributes = []): void
    {
        try {
            if (!$this->isEnabled()) {
                return;
            }
            $span = $this->activeSpan();
            $body = Scrubber::string((string) $message);
            if (strlen($body) > 16384) {
                $body = substr($body, 0, 16384);
            }
            $this->logBuf[] = [
                'time' => microtime(true),
                'severity' => self::severity($severity),
                'body' => $body,
                'attributes' => $attributes === [] ? [] : Scrubber::array($attributes),
                'traceId' => $span?->traceId,
                'spanId' => $span?->spanId,
            ];
            if (count($this->logBuf) >= self::MAX_LOGS) {
                $this->flushLogs();
            }
            $this->maybeFlush();
        } catch (\Throwable $e) {
            $this->debug('log failed: ' . $e->getMessage());
        }
    }

    /** Maps PSR-3 and Monolog level names onto the six OTLP severities. */
    public static function severity(string $level): string
    {
        return match (strtolower($level)) {
            'trace' => 'trace',
            'debug' => 'debug',
            'info', 'notice' => 'info',
            'warn', 'warning' => 'warn',
            'error' => 'error',
            'fatal', 'critical', 'alert', 'emergency' => 'fatal',
            default => 'info',
        };
    }

    // ---- metrics ----

    /** @param array<string,mixed> $attributes */
    public function count(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->metric('sum', $name, $value, $attributes, null);
    }

    /** @param array<string,mixed> $attributes */
    public function gauge(string $name, int|float $value, array $attributes = [], ?string $unit = null): void
    {
        $this->metric('gauge', $name, $value, $attributes, $unit);
    }

    /** @param array<string,mixed> $attributes */
    public function distribution(string $name, int|float $value, array $attributes = [], ?string $unit = null): void
    {
        $this->metric('histogram', $name, $value, $attributes, $unit);
    }

    /** @param array<string,mixed> $attributes */
    private function metric(string $kind, string $name, int|float $value, array $attributes, ?string $unit): void
    {
        try {
            if (!$this->isEnabled() || !is_finite((float) $value)) {
                return;
            }
            ksort($attributes);
            $key = $kind . '|' . $name . '|' . self::json($attributes);
            if (!isset($this->metricAgg[$key])) {
                if (count($this->metricAgg) >= self::MAX_SERIES) {
                    return;
                }
                $this->metricAgg[$key] = [
                    'kind' => $kind, 'name' => $name, 'unit' => $unit, 'attributes' => Scrubber::array($attributes),
                    'value' => 0.0, 'count' => 0, 'sum' => 0.0, 'min' => INF, 'max' => -INF,
                    'buckets' => array_fill(0, count(self::HIST_BOUNDS) + 1, 0),
                ];
            }
            $m = &$this->metricAgg[$key];
            $v = (float) $value;
            if ($kind === 'sum') {
                $m['value'] += $v;
            } elseif ($kind === 'gauge') {
                $m['value'] = $v;
            } else {
                $m['count']++;
                $m['sum'] += $v;
                $m['min'] = min($m['min'], $v);
                $m['max'] = max($m['max'], $v);
                $i = count(self::HIST_BOUNDS);
                foreach (self::HIST_BOUNDS as $idx => $bound) {
                    if ($v <= $bound) {
                        $i = $idx;
                        break;
                    }
                }
                $m['buckets'][$i]++;
            }
            unset($m);
            $this->maybeFlush();
        } catch (\Throwable $e) {
            $this->debug('metric failed: ' . $e->getMessage());
        }
    }

    // ---- traces ----

    public function activeSpan(): ?Span
    {
        $n = count($this->activeSpans);

        return $n === 0 ? null : $this->activeSpans[$n - 1];
    }

    /** The traceparent header for an outgoing request, or null outside a span. */
    public function traceparent(): ?string
    {
        return $this->activeSpan()?->traceparent();
    }

    /**
     * Continues a caller's trace: the next span started with no active span
     * becomes a child of this traceparent. Invalid headers are ignored.
     */
    public function continueTrace(?string $traceparent): void
    {
        $this->remoteParent = Span::parseTraceparent($traceparent);
    }

    /**
     * Starts a span under the active one (or the continued trace, or a new
     * one). With $activate it becomes the active span until it ends, so logs,
     * errors and child spans attach to it.
     *
     * @param array<string,mixed> $attributes
     * @param array{traceId:string,spanId:string,sampled:bool}|null $parent
     */
    public function startSpan(
        string $name,
        array $attributes = [],
        int $kind = Span::KIND_INTERNAL,
        bool $activate = false,
        ?array $parent = null,
        ?float $startTime = null,
    ): Span {
        try {
            $parent ??= ($p = $this->activeSpan()) !== null
                ? ['traceId' => $p->traceId, 'spanId' => $p->spanId, 'sampled' => $p->sampled]
                : $this->remoteParent;
            $span = new Span(
                $this->isEnabled() ? $this : null,
                $name,
                $kind,
                $parent,
                $attributes,
                $startTime,
                $parent === null ? $this->sampleTrace() : null,
            );
            if ($activate) {
                $this->activeSpans[] = $span;
            }

            return $span;
        } catch (\Throwable $e) {
            $this->debug('startSpan failed: ' . $e->getMessage());

            return new Span(null, $name, $kind);
        }
    }

    /**
     * Runs $fn inside a span named $name; the span ends when $fn returns or
     * throws (and the Throwable is rethrown).
     *
     * @template T
     * @param callable(Span):T $fn
     * @param array<string,mixed> $attributes
     * @return T
     */
    public function trace(string $name, callable $fn, array $attributes = []): mixed
    {
        $span = $this->startSpan($name, $attributes, Span::KIND_INTERNAL, true);
        try {
            $out = $fn($span);
        } catch (\Throwable $e) {
            $span->end($e);

            throw $e;
        }
        $span->end();

        return $out;
    }

    /** @internal called by Span::end() */
    public function finishSpan(Span $span, float $end, ?string $error): void
    {
        try {
            $i = array_search($span, $this->activeSpans, true);
            if ($i !== false) {
                // Ending a parent also ends the scope of children left open.
                array_splice($this->activeSpans, (int) $i);
            }
            if (!$span->sampled || !$this->isEnabled()) {
                return;
            }
            $out = [
                'traceId' => $span->traceId,
                'spanId' => $span->spanId,
                'name' => self::cut($span->name, 300) ?? '',
                'kind' => $span->kind,
                'startTimeUnixNano' => Otlp::nanos($span->startTime),
                'endTimeUnixNano' => Otlp::nanos(max($end, $span->startTime)),
                'attributes' => Otlp::attrs(self::scrubSpanAttributes($span->attributes)),
                'status' => $error !== null ? ['code' => 2, 'message' => Scrubber::string($error)] : ['code' => 1],
            ];
            if ($span->parentSpanId !== null) {
                $out['parentSpanId'] = $span->parentSpanId;
            }
            $this->spanBuf[] = $out;
            if (count($this->spanBuf) >= self::MAX_SPANS) {
                $this->flushSpans();
            }
            $this->maybeFlush();
        } catch (\Throwable $e) {
            $this->debug('finishSpan failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    private static function scrubSpanAttributes(array $attributes): array
    {
        foreach (['url.full', 'http.url', 'url'] as $k) {
            if (is_string($attributes[$k] ?? null)) {
                $attributes[$k] = Scrubber::stripQuery($attributes[$k]);
            }
        }

        return Scrubber::array($attributes);
    }

    private function sampleTrace(): bool
    {
        $rate = $this->options->tracesSampleRate;

        return $this->isEnabled() && ($rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate);
    }

    // ---- crons ----

    /**
     * Reports a cron run to the Argus monitor with this slug (create the
     * monitor in Argus first; an unknown slug answers 404 and is dropped).
     * Sent right away, so a start check-in opens the run when the job starts.
     *
     * @param 'start'|'ok'|'error'|int $status start, ok, error, or an exit code
     */
    public function checkIn(string $slug, string|int $status = 'ok', ?string $output = null): void
    {
        try {
            if (!$this->isEnabled() || $slug === '') {
                return;
            }
            $suffix = match (true) {
                $status === 'start' => '/start',
                $status === 'error', $status === 'fail' => '/fail',
                is_int($status) => '/' . $status,
                default => '',
            };
            /** @var Dsn $dsn */
            $dsn = $this->dsn;
            $body = $output === null ? '' : Scrubber::string($output);
            if (strlen($body) > 2048) {
                $body = substr($body, 0, 2048);
            }
            $this->transport->enqueue('crons', $dsn->ingestUrl('/v1/cron/' . rawurlencode($slug) . $suffix), $body, $this->ingestHeaders('text/plain; charset=utf-8'));
            $this->transport->flush(min(2.0, $this->options->flushTimeoutMs / 1000), 'crons');
        } catch (\Throwable $e) {
            $this->debug('checkIn failed: ' . $e->getMessage());
        }
    }

    /**
     * Runs a job and reports it: start, then ok or error. Rethrows the job's
     * Throwable after reporting it.
     *
     * @template T
     * @param callable():T $job
     * @return T
     */
    public function withMonitor(string $slug, callable $job): mixed
    {
        $this->checkIn($slug, 'start');
        try {
            $out = $job();
        } catch (\Throwable $e) {
            $this->checkIn($slug, 'error', get_class($e) . ': ' . $e->getMessage());

            throw $e;
        }
        $this->checkIn($slug, 'ok');

        return $out;
    }

    // ---- flags ----

    /**
     * Every flag's variant for a subject (usually a user id), cached for 30 s.
     * An empty array when Argus cannot be reached in flags_timeout_ms.
     *
     * @param array<string,string> $attributes
     * @return array<string,string>
     */
    public function evaluateFlags(string $subject, array $attributes = []): array
    {
        try {
            if (!$this->isEnabled() || $subject === '') {
                return [];
            }
            ksort($attributes);
            $key = $subject . '|' . self::json($attributes);
            $hit = $this->flagCache[$key] ?? null;
            if ($hit !== null && microtime(true) - $hit['at'] < 30.0) {
                return $hit['flags'];
            }
            /** @var Dsn $dsn */
            $dsn = $this->dsn;
            $res = $this->transport->request(
                'flags',
                $dsn->ingestUrl('/v1/flags/evaluate'),
                self::json(['subject' => $subject, 'environment' => $this->options->environment, 'attributes' => (object) $attributes]),
                $this->ingestHeaders('application/json'),
                $this->options->flagsTimeoutMs / 1000,
            );
            if ($res === null || !$res->ok()) {
                return $hit['flags'] ?? [];
            }
            $data = json_decode($res->body, true);
            $flags = [];
            if (is_array($data) && is_array($data['flags'] ?? null)) {
                foreach ($data['flags'] as $k => $v) {
                    if (is_scalar($v)) {
                        $flags[(string) $k] = is_bool($v) ? ($v ? 'on' : 'off') : (string) $v;
                    }
                }
            }
            if (count($this->flagCache) > 1000) {
                $this->flagCache = [];
            }
            $this->flagCache[$key] = ['at' => microtime(true), 'flags' => $flags];

            return $flags;
        } catch (\Throwable $e) {
            $this->debug('flags failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * A flag's variant, or $fallback when it is unknown or Argus is unreachable.
     *
     * @param array<string,string> $attributes
     */
    public function variant(string $key, string $subject, string $fallback, array $attributes = []): string
    {
        return $this->evaluateFlags($subject, $attributes)[$key] ?? $fallback;
    }

    /**
     * Whether a boolean flag is on for a subject; $fallback when unknown.
     *
     * @param array<string,string> $attributes
     */
    public function isOn(string $key, string $subject, bool $fallback = false, array $attributes = []): bool
    {
        $v = $this->variant($key, $subject, $fallback ? 'on' : 'off', $attributes);

        return $v === 'on' || $v === 'true';
    }

    // ---- sending ----

    /** @return array<string,string> */
    private function ingestHeaders(string $contentType): array
    {
        /** @var Dsn $dsn */
        $dsn = $this->dsn;

        return [
            'Content-Type' => $contentType,
            'Authorization' => 'Bearer ' . $dsn->publicKey,
            'User-Agent' => self::SDK_NAME . '/' . self::VERSION,
        ];
    }

    /** @return array<string,mixed> */
    private function resource(): array
    {
        return [
            'service.name' => $this->options->serviceName,
            'service.version' => $this->options->release,
            'deployment.environment' => $this->options->environment,
            'host.name' => $this->options->serverName,
            'telemetry.sdk.name' => self::SDK_NAME,
            'telemetry.sdk.version' => self::VERSION,
            'telemetry.sdk.language' => 'php',
            'argus.contract' => self::CONTRACT,
        ];
    }

    /**
     * Queues a batch, splitting it until each request is under BATCH_BYTES.
     *
     * @param list<mixed> $items
     * @param \Closure(list<mixed>):array<string,mixed> $wrap
     */
    private function enqueueBatch(string $lane, string $path, array $items, \Closure $wrap): void
    {
        if ($items === []) {
            return;
        }
        $body = self::json($wrap($items));
        if (strlen($body) > self::BATCH_BYTES && count($items) > 1) {
            $half = intdiv(count($items), 2);
            $this->enqueueBatch($lane, $path, array_slice($items, 0, $half), $wrap);
            $this->enqueueBatch($lane, $path, array_slice($items, $half), $wrap);

            return;
        }
        /** @var Dsn $dsn */
        $dsn = $this->dsn;
        // A burst bigger than the lane's 1 MB queue would evict its own first
        // half; send what is queued before adding more.
        if ($this->transport->queuedBytes($lane) + strlen($body) > Transport::MAX_BYTES) {
            $this->transport->flush($this->options->flushTimeoutMs / 1000, $lane);
        }
        $this->transport->enqueue($lane, $dsn->ingestUrl($path), $body, $this->ingestHeaders('application/json'));
    }

    private function flushLogs(): void
    {
        $this->lastLogFlush = microtime(true);
        if ($this->logBuf === [] || $this->dsn === null) {
            return;
        }
        $records = array_splice($this->logBuf, 0, self::MAX_LOGS);
        $resource = $this->resource();
        $this->enqueueBatch('logs', '/v1/logs', $records, static fn (array $r): array => Otlp::logs($resource, $r));
    }

    private function flushSpans(): void
    {
        if ($this->spanBuf === [] || $this->dsn === null) {
            return;
        }
        $spans = array_splice($this->spanBuf, 0, self::MAX_SPANS);
        $resource = $this->resource();
        $this->enqueueBatch('traces', '/v1/traces', $spans, static fn (array $s): array => Otlp::traces($resource, $s));
    }

    private function flushMetrics(): void
    {
        $end = microtime(true);
        $this->lastMetricFlush = $end;
        if ($this->metricAgg === [] || $this->dsn === null) {
            $this->metricStart = $end;

            return;
        }
        $metrics = [];
        foreach ($this->metricAgg as $m) {
            $base = ['name' => $m['name']] + ($m['unit'] !== null ? ['unit' => $m['unit']] : []);
            $attrs = Otlp::attrs($m['attributes']);
            if ($m['kind'] === 'sum') {
                $metrics[] = $base + ['sum' => [
                    'aggregationTemporality' => 1, // delta
                    'isMonotonic' => true,
                    'dataPoints' => [['attributes' => $attrs, 'startTimeUnixNano' => Otlp::nanos($this->metricStart), 'timeUnixNano' => Otlp::nanos($end), 'asDouble' => $m['value']]],
                ]];
            } elseif ($m['kind'] === 'gauge') {
                $metrics[] = $base + ['gauge' => [
                    'dataPoints' => [['attributes' => $attrs, 'timeUnixNano' => Otlp::nanos($end), 'asDouble' => $m['value']]],
                ]];
            } else {
                $metrics[] = $base + ['histogram' => [
                    'aggregationTemporality' => 1,
                    'dataPoints' => [[
                        'attributes' => $attrs,
                        'startTimeUnixNano' => Otlp::nanos($this->metricStart),
                        'timeUnixNano' => Otlp::nanos($end),
                        'count' => (string) $m['count'],
                        'sum' => $m['sum'],
                        'min' => $m['min'],
                        'max' => $m['max'],
                        'explicitBounds' => self::HIST_BOUNDS,
                        'bucketCounts' => array_map('strval', $m['buckets']),
                    ]],
                ]];
            }
        }
        $this->metricAgg = [];
        $this->metricStart = $end;
        $resource = $this->resource();
        $this->enqueueBatch('metrics', '/v1/metrics', $metrics, static fn (array $m): array => Otlp::metrics($resource, $m));
    }

    /**
     * Long-running processes (workers, daemons) have no shutdown per unit of
     * work, so buffers older than their interval go out on the next SDK call.
     */
    private function maybeFlush(): void
    {
        $now = microtime(true);
        $due = false;
        if (($this->logBuf !== [] || $this->spanBuf !== []) && $now - $this->lastLogFlush >= self::LOG_EVERY) {
            $this->flushLogs();
            $this->flushSpans();
            $due = true;
        }
        if ($this->metricAgg !== [] && $now - $this->lastMetricFlush >= self::METRICS_EVERY) {
            $this->flushMetrics();
            $due = true;
        }
        if ($due) {
            $this->transport->flush($this->options->flushTimeoutMs / 1000);
        }
    }

    /**
     * Sends everything buffered and queued. True when it all went out within
     * the timeout (default flush_timeout_ms, 2 s).
     */
    public function flush(?int $timeoutMs = null): bool
    {
        try {
            if ($this->dsn === null) {
                return true;
            }
            $this->flushLogs();
            $this->flushSpans();
            $this->flushMetrics();

            return $this->transport->flush(($timeoutMs ?? $this->options->flushTimeoutMs) / 1000);
        } catch (\Throwable $e) {
            $this->debug('flush failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Whether anything is buffered or queued. */
    public function hasPending(): bool
    {
        return $this->logBuf !== [] || $this->spanBuf !== [] || $this->metricAgg !== [] || $this->transport->pending();
    }

    /** Flushes, then stops sending. */
    public function close(?int $timeoutMs = null): bool
    {
        $ok = $this->flush($timeoutMs);
        $this->closed = true;

        return $ok;
    }

    // ---- helpers ----

    /** @internal */
    public function debug(string $message): void
    {
        $line = '[argus] ' . $message;
        try {
            if ($this->options->logger !== null) {
                ($this->options->logger)($line);
            } elseif ($this->options->debug) {
                error_log($line);
            }
        } catch (\Throwable) {
            // never let debug output break the app
        }
    }

    private static function cut(?string $s, int $max): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }

        return strlen($s) > $max ? substr($s, 0, $max) : $s;
    }

    /**
     * Drops empty arrays at any depth. PHP encodes an empty map as `[]`, which a
     * strict decoder on the other side reads as the wrong type and refuses the
     * whole event over.
     *
     * @param array<array-key,mixed> $a
     * @return array<array-key,mixed>
     */
    private static function pruneEmpty(array $a): array
    {
        $list = array_is_list($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $v = self::pruneEmpty($v);
                if ($v === []) {
                    unset($a[$k]);
                    continue;
                }
                $a[$k] = $v;
            }
        }

        return $list ? array_values($a) : $a;
    }

    /** JSON that never fails: invalid UTF-8 is substituted, unencodable parts are nulled. */
    public static function json(mixed $value): string
    {
        $out = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );

        return $out === false ? 'null' : $out;
    }
}
