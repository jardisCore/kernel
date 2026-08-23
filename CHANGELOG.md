# Changelog

All notable changes to `jardiscore/kernel` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [2.0.1] — 2026-08-22

Documentation-only release (env-konfiguration, P6): "Koffer" renamed to
"DomainKernel" across README/CLAUDE.md/docs, `core-kernel` skill rewritten
for v2, stale `.backup` skill directories dropped.

## [2.0.0] — 2026-08-22 — R2–R4 Project-Root Convention + Messaging (env-konfiguration, P3/P4)

### Changed (BREAKING)

- **Project-root convention (R2).** `DomainKernel`'s first constructor
  parameter is renamed `domainRoot` → `projectRoot` and now carries the
  project root (the git-clone target), not a config path.
  `BuildDomainKernelFromEnv::__invoke(string $projectRoot)` takes the project
  root as well and reads `<projectRoot>/config/env` (created via race-safe
  `mkdir` if missing) instead of being handed the config directory directly.
  `DomainKernel::projectRoot()` (renamed from `domainRoot()`, contracts
  v2.0.0) returns exactly the path passed in, never the internal `config/env`
  path.
- **No `$_ENV`/process-environment fallback in `env()` (R3, G16).** The
  kernel is file-pure: the removed fallback looked up a lowercase key against
  the process environment, which never matched Docker's UPPERCASE
  `environment:` entries (BEFUNDE §1b). An explicitly empty value counts as
  "missing" (uniform with the packer's `$envGet`, R1.3 rule 1).
- Consumes released `jardissupport/contracts ^2.0` (path-repo dropped).

### Added

- `DomainKernel::messaging(): ?MessagingServiceInterface` — a new, twelfth
  accessor, and `Bootstrap\Handler\BuildMessagingFromEnv`, wired into
  `BuildDomainKernelFromEnv` as a new constructor parameter appended after
  `array $env` (BC-safe position). Requires `jardisadapter/messaging`
  (composer `suggest` + `require-dev`, degrades to `null` via the
  established `class_exists()` guard when not installed).
- `MESSAGING_TRANSPORT=kafka|rabbitmq|redis` selects the transport;
  unset/empty ⇒ `null`; any other value throws
  `InvalidEnvConfigurationException`. `KAFKA_BROKERS` carries the full
  comma-separated broker list straight into the adapter's `ConnectionFactory`
  — no separate `KAFKA_PORT` key, closing the "Kafka-Falle" documented in
  BEFUNDE.md §5 (`ConnectionFactory::kafka()` ignores a standalone port).
  `RABBITMQ_HOST/PORT/USER/PASSWORD` for RabbitMQ — `RABBITMQ_HOST` (like
  `KAFKA_BROKERS`) has no implicit default and is required once the
  transport is chosen; missing/empty throws `InvalidEnvConfigurationException`
  at boot. Redis reuses the same
  `REDIS_*` keys as the cache accessor (one stack, one Redis) but opens its
  own connection — never the cache/logger's shared client, since `consume()`
  blocks the connection for as long as it waits. Uses Redis **Streams**, not
  Pub/Sub — measured directly against a real broker: Pub/Sub only delivers
  to a subscriber already listening at publish time (no queue behind it),
  and repeated publish-then-consume roundtrips against phpredis' own
  subscribe-loop proved unreliable in this environment (sometimes delivered
  within seconds, sometimes hung indefinitely, identical code). Streams give
  at-least-once delivery independent of subscriber timing.
- NEW `docs/env-examples/.env.messaging.example`; `.env.example`'s cascade
  gained `load?(.env.messaging)`.
- `support/docker-compose.yml`: `kernel-test-redis` / `kernel-test-rabbitmq`
  / `kernel-test-kafka` broker services (own container names/host ports,
  distinct from `adapter/messaging`'s own compose) for the messaging
  Integration suite; `make start`/`make stop` bring them up/down.
- `tests/Integration/Bootstrap/BuildMessagingFromEnvTest.php` — publish +
  consume/read roundtrips against real Docker brokers for all three
  transports, plus the error-rule and G14 boundary cases.

### Fixed

- **A configured-but-incomplete transport now fails at boot, not on first
  `publish()`.** The connection object for the chosen transport
  (`ConnectionFactory::kafka()`/`rabbitMq()`/`redis()`) used to be built only
  inside a lazy closure — an empty `KAFKA_BROKERS` reached
  `ConnectionConfig`'s constructor validation ("Host cannot be empty") only
  once something actually called `publish()`, as a raw, unwrapped
  `InvalidArgumentException` straight from the adapter, and the `try`/`catch`
  around the dispatch in `BuildMessagingFromEnv::__invoke()` was dead code —
  it wrapped a `match()` call whose branches never threw synchronously.
  Connection construction now happens eagerly in `buildKafka()`/
  `buildRabbitMq()`/`buildRedis()`, so that `try`/`catch` is live, and
  `RABBITMQ_HOST`/`REDIS_HOST` lost their `localhost` default for the same
  reason `KAFKA_BROKERS` never had one — each is explicitly required once
  its transport is chosen, checked via `IsEnvValueUnset` before the
  connection is even built. Reachability failures stay lazy (deferred to
  the adapter's own `connect()` inside `publish()`/`consume()`), only the
  config *shape* became eager (G5 rule 2 vs. rule 3).

### Known limitations (documented, not fixed here)

- **Kafka consuming is not available through `DomainKernel::messaging()`.**
  A Kafka consumer needs a consumer group ID; there is no `MESSAGING_*` key
  for one (deliberately out of scope, G14) — `messaging()->consume()` throws
  a `RuntimeException` explaining this; `publish()` is unaffected. Build a
  `ConnectionFactory::kafkaConsumer($brokers, $groupId)` directly instead.
- **RabbitMQ has no canonical queue-name key.** `messaging()->consume($topic,
  ...)` uses `$topic` itself as the RabbitMQ queue name, bound under that
  name to the default topic exchange — the same "topic names the
  destination" contract Kafka and Redis already give the caller.
- **Redis consuming uses Streams (`XREAD ... BLOCK`), not Pub/Sub.** phpredis
  reuses its connection "timeout" as the blocking-command read timeout too;
  this Handler sets it to `0` (phpredis' "use the ini default" convention,
  effectively `default_socket_timeout`, ~60s) for the consumer connection so
  a `block` option shorter than that doesn't spuriously throw a client-side
  read error before the server's own `BLOCK` window elapses.

## [1.2.0] — 2026-08-22 — R1 Kernel Bugfixes (env-konfiguration, P2)

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
