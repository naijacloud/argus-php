# Changelog

All notable changes to `naijacloud/argus` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-26

- **Requires a patched PHP:** 8.2.35, 8.3.36, 8.4.27, 8.5.12 or newer, the
  releases that fix php2shell (CVE-2026-109618 and CVE-2026-109619).
  Composer refuses older releases. PHP 8.1 is end of life and unsupported.

First release, implementing the Argus SDK contract `argus.sdk/1`.

### Added

- `\NaijaCloud\Argus\init()` and the static `Argus` class. Reads `ARGUS_DSN`,
  `ARGUS_RELEASE` and `ARGUS_ENVIRONMENT`, falling back to the `SENTRY_*`
  variables. Without a DSN the SDK does nothing.
- **Errors** as Sentry envelopes (`sdk.name` `argus.php`, `platform` `php`):
  - `captureException()` with the `getPrevious()` chain, stack frames oldest
    first, source context and `in_app`;
  - `captureMessage()`, user, tags, contexts, extra and breadcrumbs (last 100);
  - `before_send` and `before_breadcrumb`;
  - the same exception is sent once.
- **Global handlers** for uncaught exceptions, PHP errors and fatal errors at
  shutdown. Previous handlers are chained, and PHP's own output and exit code
  are unchanged.
- **Logs, metrics and traces** over OTLP/HTTP JSON: `Argus::logs()`,
  `Argus::metrics()` (counters, gauges, histograms), `Argus::trace()`,
  `startSpan()`, `continueTrace()` and `traceparent()`.
- `Log\PsrLogger` (psr/log 1 to 3) and `Log\MonologHandler` (Monolog 2 and 3).
  Neither package is required.
- **Cron check-ins by monitor slug:** `Argus::cron()->checkIn()` and
  `withMonitor()`.
- **Feature flags:** `Argus::flags()->isOn()`, `variant()` and `all()`, cached
  for 30 seconds.
- **Request-scoped sending:** everything is buffered during the request, then
  sent at shutdown after `fastcgi_finish_request()`, within a 2-second budget.
- **Transport:**
  - a queue per endpoint (100 requests, 1 MB);
  - backoff on network errors and 5xx;
  - `Retry-After` on 429;
  - a stop on 401 and 403;
  - gzip for error envelopes;
  - curl, or PHP streams when curl is missing;
  - never throws.
- **Privacy defaults on:** sensitive keys, card numbers, tokens and JWTs are
  filtered, request bodies and cookies are not sent, and IP addresses are not
  sent unless `send_default_pii` is on.
