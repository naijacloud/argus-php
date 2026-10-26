# Contributing

## What this package is

One of several SDKs implementing the Argus SDK contract, `argus.sdk/1`
(`spec/README.md` in nc-argus). The wire format, the transport rules and the
privacy defaults are the same in every language, so a bug fixed in one is a
bug fixed in all.

If the contract and this code disagree, the contract wins. If Argus itself
disagrees with the contract, Argus wins and the contract gets fixed.

`naijacloud/argus-laravel` builds on this package. A change to the public API
here is a change it has to absorb.

## Getting set up

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

PHP 8.1 or newer. The runtime needs `ext-json` and nothing else, and it should
stay that way: every runtime dependency is one more thing an app's own
constraints have to agree with. `psr/log` and `monolog/monolog` are dev
dependencies so their adapters are tested and analysed, and only suggested to
users.

## Tests

**The suite runs offline.** A test that needs the network fails in someone's
CI for reasons unrelated to their change.

- `tests/Support/MockServer.php` spawns `php -S` on a loopback port to stand
  in for Argus ingest. It serves `tests/Support/router.php` and records every
  request it gets. The real curl and stream clients talk to it, so headers,
  gzip, status handling and backoff run as they do in production. The
  scenario rides in the DSN's path prefix (`/s/<scenario>/<project>`), so no
  test reaches inside the SDK to make the server misbehave.
- Backoff is recorded, not slept: a test passes a `sleeper`, and the client
  advances its clock by what it would have waited.
- `tests/HandlersTest.php` runs the scripts in `tests/Support/scripts/` as
  real PHP processes. Exit codes, PHP's own error output and the shutdown
  flush only show their behaviour there.

Adding a behaviour means adding a scenario to the router and a test named for
the reason it exists.

The contract test (`composer test:contract`, `tests/contract/run.php`) talks
to a real Argus and is not part of the suite:

```bash
ARGUS_CONTRACT_DSN=<a test source DSN> composer test:contract
```

## House style

- `declare(strict_types=1)` in every file. PSR-12, PSR-4 (`NaijaCloud\Argus\`
  → `src/`).
- **Comments explain why:** the decision, the failure it prevents, or the trap
  it avoids.
- **No emoji,** anywhere.
- **SDK code never throws into the app.** Every public method catches what it
  could raise.
- **Internal code:** anything under `src/Internal/` and the transport's queue
  classes is `@internal` and carries no compatibility promise.

## Before you open a pull request

Run what CI runs:

```bash
composer validate
composer install
find src tests examples -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit
```

CI also runs the suite on PHP 8.1 to 8.4, without `ext-curl`, and with the
lowest dependency versions the ranges allow.

## Releasing

1. `Client::VERSION` and the `version` field in `composer.json` are the same
   string. Change both.
2. Move the `Unreleased` entries in `CHANGELOG.md` under the new version with
   a date.
3. Tag and push `vX.Y.Z`. Packagist picks it up from the tag.

`.github/workflows/release.yml` checks the tag before creating the GitHub
release:

- it runs the CI matrix;
- it refuses a tag that disagrees with `composer.json` or `Client::VERSION`,
  because Packagist silently skips a tag whose name and `version` field
  disagree;
- it refuses a version with no changelog section.

It then creates the GitHub release from that section.

### One-time setup

- **packagist.org → Submit** with `https://github.com/naijacloud/argus-php`,
  signed in with a Packagist account linked to a GitHub account that has
  admin on the repository. Packagist installs its GitHub hook, which makes
  each tag show up within a minute.
- **Fallback if the hook is not installed:** repository secrets
  `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN`. The release workflow then asks
  Packagist to re-read the repository.

## Security

Do not report a vulnerability through an issue or a pull request. See
[SECURITY.md](SECURITY.md).
