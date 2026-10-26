<p align="center">
  <a href="https://www.naijacloud.com/argus">
    <img alt="Naijacloud Argus, PHP SDK" src="https://raw.githubusercontent.com/naijacloud/argus-php/main/.github/assets/banner.png" width="100%">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/naijacloud/argus"><img alt="packagist" src="https://img.shields.io/badge/packagist-naijacloud%2Fargus-008751?style=flat-square&labelColor=0A0E0C"></a>
  <img alt="php" src="https://img.shields.io/badge/php-%3E%3D_8.1-8892BF?style=flat-square&labelColor=0A0E0C">
  <img alt="dependencies" src="https://img.shields.io/badge/dependencies-ext--json_only-46C98A?style=flat-square&labelColor=0A0E0C">
  <a href="LICENSE"><img alt="license" src="https://img.shields.io/badge/license-MIT-8A988F?style=flat-square&labelColor=0A0E0C"></a>
</p>

<p align="center">
  <a href="#install">Install</a> ·
  <a href="#errors">Errors</a> ·
  <a href="#logs-metrics-and-traces">Logs, metrics, traces</a> ·
  <a href="#psr-3-and-monolog">PSR-3 and Monolog</a> ·
  <a href="#cron-check-ins">Crons</a> ·
  <a href="#feature-flags">Flags</a> ·
  <a href="#how-sending-works">Sending</a> ·
  <a href="#privacy">Privacy</a> ·
  <a href="#options">Options</a>
</p>

# naijacloud/argus

The official PHP SDK for [Argus](https://www.naijacloud.com/argus),
Naijacloud's monitoring. One package sends errors, logs, metrics, traces, cron
check-ins and feature-flag checks, with one key: your app's **DSN**.

It needs nothing but `ext-json`, implements the Argus SDK contract
`argus.sdk/1`, and works in plain PHP, Symfony, Slim, WordPress or anything
else, for apps hosted anywhere, not only on Naijacloud.

**On Laravel?** Install
[`naijacloud/argus-laravel`](https://github.com/naijacloud/argus-laravel)
instead. It uses this package and wires everything into Laravel for you.

**Already using Sentry's PHP SDK?** Keep it. Argus accepts it: point its DSN at
Argus and you're done. Switch to this SDK when you want logs, metrics, cron
check-ins and flags through the same setup.

## Install

```sh
composer require naijacloud/argus
```

PHP 8.1 or newer. `ext-curl` is used when it is loaded; without it the SDK
sends through PHP's own `https://` stream wrapper.

Copy the DSN (your app's key) from the source in Argus. It looks like
`https://<key>@argus-in.naijacloud.com/<number>`. Then, as early as possible in
your front controller:

```php
require __DIR__ . '/vendor/autoload.php';

\NaijaCloud\Argus\init([
    'dsn' => 'https://…@argus-in.naijacloud.com/41', // or set ARGUS_DSN
    'release' => '1.4.2',
]);
```

Or call `init()` with no DSN and set the environment instead:

```sh
ARGUS_DSN=https://…@argus-in.naijacloud.com/41
ARGUS_RELEASE=1.4.2
ARGUS_ENVIRONMENT=production
```

`init()` reads `ARGUS_DSN`, `ARGUS_RELEASE` and `ARGUS_ENVIRONMENT`, falling
back to `SENTRY_DSN`, `SENTRY_RELEASE` and `SENTRY_ENVIRONMENT`. Without a DSN
it does nothing and never throws. Apps hosted on Naijacloud get these
variables set when the Argus tab is on.

That's it. You get:

- **Uncaught exceptions,** reported as `fatal`. PHP still prints its usual
  "Uncaught ..." message and exits with code 255, as it does without Argus.
- **PHP warnings and errors** (`E_WARNING`, `E_USER_ERROR` and the like), once
  per file and line. Calls silenced with `@` are skipped. Deprecations and
  notices become breadcrumbs on the next error.
- **Fatal errors** at shutdown, including running out of memory.
- **Any handler you already had is kept.** Argus calls your previous exception
  and error handlers after it records the error.

`\NaijaCloud\Argus\init()` and `\NaijaCloud\Argus\Argus::init()` are the same.
The examples below use the `Argus` class.

## Errors

```php
use NaijaCloud\Argus\Argus;

try {
    $paystack->charge($order);
} catch (\Throwable $e) {
    Argus::captureException($e, ['tags' => ['provider' => 'paystack'], 'extra' => ['orderId' => $order->id]]);
}

Argus::captureMessage('Webhook signature mismatch', 'warning');
Argus::setUser(['id' => $user->id]);
Argus::setTag('region', 'af-west');
Argus::addBreadcrumb(['category' => 'checkout', 'message' => 'Applied coupon']);
```

- **Causes:** an exception's `getPrevious()` chain is sent too.
- **Duplicates:** the same exception object is sent once, however many
  handlers see it.
- **Stack traces** carry the source lines around each frame. Frames under
  `vendor/` are marked as not your code.
- **`before_send`** lets you change an event, or return `null` to drop it.

The second argument to `captureException` and `captureMessage` takes `level`,
`tags`, `extra`, `user`, `fingerprint`, `transaction` and `contexts`.

## Logs, metrics and traces

```php
use NaijaCloud\Argus\Argus;
use NaijaCloud\Argus\Span;

Argus::logs()->info('order placed', ['orderId' => 'ord_81', 'total' => 18500]);
Argus::logs()->error('paystack timed out', ['attempt' => 3]);

Argus::metrics()->count('orders.placed', 1, ['method' => 'card']);
Argus::metrics()->gauge('queue.depth', count($jobs));
Argus::metrics()->distribution('checkout.ms', $elapsedMs, [], 'ms');

Argus::trace('charge card', function (Span $span) use ($order) {
    $span->setAttribute('provider', 'paystack');
    return $paystack->charge($order);
});
```

- **Logs** have six levels: `trace`, `debug`, `info`, `warn`, `error` and
  `fatal`. A log written inside a span carries that span's trace id.
- **Metrics** are added up in the process and sent as one request: counters,
  gauges and histograms.
- **Spans** started inside another span become its children. `trace()` ends
  the span when your callback returns or throws, and rethrows.
- **Continuing a trace** from an incoming request:
  `Argus::continueTrace($_SERVER['HTTP_TRACEPARENT'] ?? null)`, then start
  your request span.
- **Outgoing requests:** add `traceparent: Argus::traceparent()` to the
  headers so the service you call joins the same trace.
- **A span you end yourself:**
  `$span = Argus::startSpan('import', [], Span::KIND_INTERNAL, activate: true); … $span->end();`
- **Sample traces** with `traces_sample_rate` (0 to 1, default 1).

## PSR-3 and Monolog

Two adapters write to Argus Logs. Neither library is a requirement of this
package: install the one you use.

```php
// PSR-3 (psr/log 1, 2 or 3)
$logger = new \NaijaCloud\Argus\Log\PsrLogger();
$logger->warning('Paystack slow: {ms} ms', ['ms' => 2300]);

// Monolog 2 or 3
$log = new \Monolog\Logger('app', [new \NaijaCloud\Argus\Log\MonologHandler()]);
```

- **Placeholders** like `{ms}` are filled from the context, and the context
  becomes the log's attributes.
- **Exceptions:** a `Throwable` under the `exception` context key, at `error`
  or above, is also sent as an Argus error. It is sent once, even when another
  handler reports it too.

## Cron check-ins

Create a cron monitor in Argus first, then report runs by its slug:

```php
use NaijaCloud\Argus\Argus;

Argus::cron()->withMonitor('nightly-invoices', fn () => sendInvoices());

// or by hand:
Argus::cron()->checkIn('nightly-invoices', 'start');
Argus::cron()->checkIn('nightly-invoices', 'ok'); // or 'error', or an exit code
```

- **`withMonitor`** reports the start, then success or failure, and rethrows
  your job's exception.
- **Check-ins are sent immediately,** not at shutdown, so a run's start
  reaches Argus when the job starts.
- **An unknown slug** answers 404. The SDK drops it and carries on.

## Feature flags

```php
use NaijaCloud\Argus\Argus;

if (Argus::flags()->isOn('new-checkout', (string) $user->id)) {
    // …
}
$theme = Argus::flags()->variant('theme', (string) $user->id, 'light');
$all = Argus::flags()->all((string) $user->id, ['plan' => 'pro']);
```

- **Caching:** answers are cached for 30 seconds per user, for the life of the
  PHP process.
- **Timeout:** a flag check waits at most `flags_timeout_ms` (2 seconds by
  default).
- **Fallback:** if Argus can't be reached, or the flag doesn't exist, you get
  the fallback you passed.

## How sending works

PHP handles one request per process run, so the SDK buffers during the request
and sends when it ends:

1. Errors, logs, spans and metrics are queued in memory while your code runs.
   Nothing is sent mid-request, so nothing slows your page down.
2. At shutdown, after your other shutdown functions, the SDK calls
   `fastcgi_finish_request()` when PHP-FPM (or LiteSpeed) provides it. The
   visitor gets the response, then the SDK sends.
3. Sending is bounded: `flush_timeout_ms`, 2 seconds by default, is the most a
   PHP worker spends on Argus.

The transport follows the Argus contract:

- **One queue per endpoint,** at most 100 requests and 1 MB. When it is full,
  the oldest is dropped.
- **Retries:** network errors and 5xx back off (1 s, 2 s, 4 s …), at most 5
  tries, within the flush budget.
- **429** pauses that endpoint for its `Retry-After`.
- **401 and 403** stop that endpoint until the process restarts.
- **Any other 4xx** drops the request.
- **Nothing throws:** SDK code never throws into your app.

**Long-running processes** (queue workers, daemons, ReactPHP, RoadRunner) have
no per-request shutdown. Buffers older than 5 seconds (10 for metrics) go out
on the next SDK call, but call `Argus::flush()` after each unit of work to be
sure, and `Argus::clearScope()` so one job's user and tags don't leak into the
next. CLI scripts need nothing: shutdown flushes them.

## Privacy

On by default:

- **Sensitive keys:** the values of keys such as `password`, `token`,
  `secret`, `authorization`, `cookie`, `bvn` and `card` are replaced with
  `[Filtered]`, at any depth.
- **Inside strings:** card numbers, bearer tokens and JWTs are filtered.
- **Not sent:** request bodies and cookies.
- **URLs:** query strings are removed from request and span URLs.
- **IP addresses:** the user's IP address is not sent unless you set
  `send_default_pii => true`.

Argus filters again on arrival.

## Options

| Option | Default | |
| -- | -- | -- |
| `dsn` | `ARGUS_DSN`, then `SENTRY_DSN` | Without one, nothing is sent |
| `release` | `ARGUS_RELEASE`, then `SENTRY_RELEASE` | Your app's version, like `1.4.2` or a git commit |
| `environment` | `ARGUS_ENVIRONMENT`, then `SENTRY_ENVIRONMENT`, else `production` | |
| `service_name` | `ARGUS_SERVICE_NAME`, else your `composer.json` name, else `app` | Shown on logs, metrics and traces |
| `server_name` | the host name | |
| `sample_rate` | `1` | Share of errors sent |
| `traces_sample_rate` | `1` | Share of traces sent |
| `send_default_pii` | `false` | Send the user's IP address |
| `max_breadcrumbs` | `100` | |
| `before_send` / `before_breadcrumb` | | `fn (array $event): ?array`: change or drop |
| `tags` | `[]` | Tags on every event |
| `ignore_exceptions` | `[]` | Exception classes never sent |
| `register_handlers` | `true` | Install the exception and error handlers |
| `error_types` | `E_ALL` minus deprecations and notices | PHP errors sent as events |
| `flush_timeout_ms` | `2000` | Most time spent sending at shutdown |
| `flags_timeout_ms` | `2000` | Most time a flag check waits |
| `finish_request` | `true` | Call `fastcgi_finish_request()` before sending |
| `context_lines` | `true` | Send source lines around stack frames |
| `project_root` | the directory holding `vendor/` | Frames under it are your code |
| `in_app_exclude` | `['/vendor/']` | Path fragments that are not your code |
| `http_client` | curl, else streams | Any `NaijaCloud\Argus\Transport\HttpClient` |
| `debug` | `false` | Write what the SDK does to the PHP error log (or set `ARGUS_DEBUG=1`) |
| `enabled` | `true` | Turn the SDK off without removing it |

## Check it works

```php
\NaijaCloud\Argus\init(['dsn' => 'https://…@argus-in.naijacloud.com/41', 'debug' => true]);
\NaijaCloud\Argus\Argus::captureMessage('Argus test event from argus-php');
var_dump(\NaijaCloud\Argus\Argus::flush()); // true when Argus accepted it
```

With `debug` on, a refused key or a rate limit is written to the PHP error
log.

## Not in this version

These are planned, and not done yet:

- automatic spans for PDO queries and outgoing Guzzle or PSR-18 requests
  (the Laravel package does queries and outgoing `Http::` calls);
- a Symfony bundle (the core works in Symfony today, by calling `init()` in
  `public/index.php`);
- an `argus` CLI command in this package (use
  [`argus-cli`](https://github.com/naijacloud/argus-cli) `test`).

## License

MIT
