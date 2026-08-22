# Changelog

All notable changes to `jardiscore/kernel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] — R1 Kernel Bugfixes (env-konfiguration, P2)

Family-grep across all 11 `src/Bootstrap/Handler/*.php` files against the
pattern "Roh-String comparison against an already-cast value" (the class of
bug behind `BuildHttpClientFromEnv`'s SSL-verification flip).

### Fixed

- **`HTTP_VERIFY_SSL` no longer inverts SSL verification.** DotEnv casts a
  literal `true`/`false` to `bool`, and a bare `1`/`0` to `int` — never the
  raw string. The prior `($env('http_verify_ssl') ?? 'true') === 'true'`
  comparison silently read `bool(true)` as `false`, switching SSL
  verification **off** for the common `HTTP_VERIFY_SSL=true` case. Fixed via
  a shared `NormalizeEnvBool` unit, also applied to `DB_POOL_VALIDATE_CONNECTIONS`
  and `DB_POOL_STICKY_WRITER` (`BuildConnectionPoolConfigFromEnv`), which had
  the identical bug.
- **Credential values survive DotEnv's cast chain as strings.** `DB_PASSWORD=false`
  used to reach the connection as `bool(false)` (then `(string)`-cast to
  `''`); `DB_PASSWORD=123456` became an `int`. The packer now registers
  `*_PASSWORD`/`*_USER`/`*_SECRET`/`*_TOKEN` as DotEnv raw keys
  (`DotEnv::addRawKeys()`, jardissupport/dotenv ^1.2) before loading — those
  keys are exempt from casting everywhere in the cascade.

### Changed (behaviour)

Two intentional, documented behaviour changes — both close a silent failure
mode the pre-R1 code had:

1. **A configured but invalid or unreachable service now throws
   `InvalidEnvConfigurationException` instead of degrading to `null` behind a
   bare `error_log`.** Applies to: an invalid `DB_POOL_LOAD_BALANCING_STRATEGY`
   (previously swallowed into the plain-PDO fallback — the exact
   "Verschluck-Szenario" this closes), an unreachable configured DB host
   (mysql/pgsql/sqlite), and an unreachable configured Redis host. A
   genuinely **missing** configuration (no `DB_HOST` at all, or an
   unconfigured optional adapter) is unaffected and still degrades to `null`.
2. **An explicitly empty ENV value (`KEY=`) is now uniformly treated as
   "missing"**, not as "set to the empty string" — centralized in
   `BuildDomainKernelFromEnv`'s `$envGet` and `DomainKernel::env()`. Note:
   DotEnv's own cast chain already collapses an empty value to the literal
   `bool(false)` (`filter_var('', FILTER_VALIDATE_BOOLEAN)` treats `""` as
   false) for every key, not just boolean-shaped ones — a new
   `IsEnvValueUnset` unit treats `null`, `''` and this cast `false`
   equivalently at every string-shaped presence check (`db_host`,
   `db_reader{n}_host`, `mail_host`, `redis_host`, `log_handlers`); a
   genuinely boolean key keeps `false` as a real, valid value via
   `NormalizeEnvBool`'s `is_bool()` passthrough.

### Added

- `Bootstrap\Handler\NormalizeEnvBool` — the single ENV-to-bool unit every
  handler with a boolean key now uses.
- `Bootstrap\Handler\IsEnvValueUnset` — the single "is this ENV value
  configured" check for string-shaped presence checks.
- `Bootstrap\Data\CredentialEnvKeySuffixes` — the four credential-shaped
  suffixes the packer registers as DotEnv raw keys.
- `Exception\InvalidEnvConfigurationException` — thrown for both an invalid
  ENV value and a configured-but-unreachable service (see above).
- `tests/Integration/Bootstrap/` — a new PHPUnit test suite that exercises
  Handlers through a REAL DotEnv cascade (fixture `.env` files), not the
  Roh-String closures the old Unit tests fed directly (BEFUNDE.md §1c: those
  tests structurally could not have caught the bugs above).

### Dependencies

- `jardissupport/dotenv` bumped to `^1.2` (raw-key support, `addRawKeys()`).
