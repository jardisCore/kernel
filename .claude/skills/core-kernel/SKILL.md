---
name: core-kernel
description: jardiscore/kernel v2 - the immutable DomainKernel (12 nullable/typed accessors + container(), incl. eventListenerRegistry, messaging, auth) plus the optional Bootstrap-Packer BuildDomainKernelFromEnv (projectRoot-based: ONE `<projectRoot>/.env` plus its cascade, or an `.env`-formatted string; process environment always wins; secret key from APP_SECRET_KEY or `<projectRoot>/support/secret.key`; auth() from AUTH_TOKEN_KEY). DomainApp/BoundedContext/ServiceRegistry/Response pipeline were removed (Kernel-Entkopplung 2026-07) - the generated {Domain}Context now carries handle()/context()/resource()/payload()/version()/result(). TRIGGER: DomainKernel, DomainKernelInterface, projectRoot, BuildDomainKernelFromEnv, Bootstrap-Packer, eventListenerRegistry, messaging(), auth(), AUTH_TOKEN_KEY, .env im Root, envContent string mode, APP_SECRET_KEY, secret.key, InvalidEnvConfigurationException. Formerly (now generated, not in this package): DomainApp, BoundedContext, ServiceRegistry, SharedRegistry, ContextResponse, DomainResponse.
user-invocable: false
zone: post-active
persona: C
prerequisites: [foundation-architecture, foundation-patterns]
next: [generated-code-extend, core-app]
---

# KERNEL_COMPONENT_SKILL
> `jardiscore/kernel` v2 | NS: `JardisCore\Kernel` | PHP 8.2+

## Kernel-Entkopplung (2026-07) — what changed

`jardiscore/kernel` is now a pure **Application-layer offering**, outside the
hexagonal inner rings. The former domain-side classes are **deleted**:
`DomainApp`, `ServiceRegistry`, `BoundedContext`, `Response/*`
(`ContextResponse`, `DomainResponse`, `DomainResponseTransformer`,
`ResponseStatus`). What replaced them:

- **The Jardis Builder now generates** the domain's Context body
  (`{Domain}Context`, 1:1-structural port of the former `BoundedContext` —
  `handle()`/`context()` (now `protected`), `resource()/payload()/version()/
  result()`) and the **Response-Trio** (`ContextResponse`/`DomainResponse`/
  `DomainResponseTransformer`) per domain, under `{Domain}\Response\`. See
  the `generated-code-extend` skill for that generated-code contract — this
  package does **not** define those classes anymore.
- **Shared vocabulary moved to `jardissupport/contracts`:** `ResponseStatus`
  (enum), `GeneratedContextInterface` (the D5 marker every generated
  `{Domain}Context` implements), `DomainKernelInterface` (13 methods incl.
  `container()`, `eventListenerRegistry()`, `messaging()` and `auth()`).
- **This package keeps exactly two things:** the immutable `DomainKernel`
  (implements `DomainKernelInterface`) and an optional ENV-driven packer
  (`Bootstrap\BuildDomainKernelFromEnv`) that builds one. Generated domains
  take the `DomainKernel` via constructor injection — `new {Domain}($kernel)`
  — nothing extends anything in this package anymore.
- **No more static shared state.** `ServiceRegistry`'s first-write-wins
  sharing is gone (G11) — sharing services across domains is now explicit:
  pass the **same** `DomainKernel` instance to every domain facade that should
  share it; build a separate `DomainKernel` for a domain that needs its own.

## Env-Konfiguration (2026-08, R1–R4) — what changed since v1

Second breaking wave, tracked in `docs/env-konfiguration/` (repo
`devops/jardis-app-template`, the run's home):

- **`domainRoot()` → `projectRoot()` rename** (contracts v2.0.0). The packer
  takes the **project root**, and since v2.4.0 that root is also where the ONE
  `.env` lives (see the v2.4.0 section below). `DomainKernel::projectRoot()`
  returns exactly the path passed to the packer's `__invoke()`.
- **Dead `$_ENV` fallback removed.** `DomainKernel::env()` and the packer's
  internal `$envGet` closure read **only** the private ENV loaded from files
  — no lowercase-key lookup against the process environment (it never
  matched Docker's UPPERCASE `environment:` entries anyway). An explicitly
  empty value (`KEY=`) is treated as "missing" (`null`), uniformly, in both
  places.
- **Bool ENV values are normalized, not string-compared.** `NormalizeEnvBool`
  is the one shared unit every boolean-shaped Handler ENV key goes through
  (`bool`/`0`/`1`/`filter_var(FILTER_NULL_ON_FAILURE)`); an unparsable value
  (e.g. `HTTP_VERIFY_SSL=maybe`) throws `InvalidEnvConfigurationException`
  instead of silently degrading. Fixes a prior security bug where
  `HTTP_VERIFY_SSL=true` (the string, cast to `bool(true)`) compared `===
  'true'` and disabled SSL verification.
- **Invalid config never degrades.** A typo in
  `DB_POOL_LOAD_BALANCING_STRATEGY` or an unreachable **configured** Redis host
  fails loudly with `InvalidEnvConfigurationException` (missing config still
  degrades to `null`, that distinction is intentional). `BuildConnectionFromEnv`
  has no `catch (\Throwable)` and no plain-PDO fallback any more (Entscheid O23
  2026-10-16): a configured database without `jardisadapter/dbconnection` (^1.4)
  throws `InvalidEnvConfigurationException` while building, without I/O.
- **Credential-shaped ENV keys survive the cast chain as strings.**
  `Bootstrap\Data\CredentialEnvKeySuffixes` (`_PASSWORD`, `_USER`, `_SECRET`,
  `_TOKEN`) are registered via `DotEnv::addRawKeys()` before `loadPrivate()`
  — `DB_PASSWORD=false`/`=123456` reach the handlers as the literal string,
  not a cast `bool`/`int`.
- **`messaging()` accessor, real (not just container-reachable).** New
  12th method on `DomainKernelInterface`; the packer's
  `Bootstrap\Handler\BuildMessagingFromEnv` bootstraps it from
  canonical ENV keys (no connection opened while building) (see dedicated section below) — see the
  G11-accessor rule in `[[domainkernel-ist-die-infrastrukturflaeche]]`
  (wissensbasis): a named accessor exists exactly when the kernel itself
  bootstraps the service from canonical ENV keys.

## ARCHITECTURE

```
Bootstrap\BuildDomainKernelFromEnv (optional ENV packer, one invokable class)
    → packs
DomainKernel implements DomainKernelInterface (immutable,
                                                constructor injection, 13 methods)
    ↓ passed to
new {Domain}($kernel)               ← generated Domain facade (final, JardisCore-free)
    ↓ new {BC}($kernel)              ← generated BC facade, extends {Domain}Context
{Domain}Context                     ← GENERATED per domain (generated-code-extend skill)
    handle()/context() (Kernel-Naht, protected) · resource()/payload()/version()/result()
    ↓ result()
ContextResponse (GENERATED) → DomainResponseTransformer (GENERATED) → DomainResponse (GENERATED)
```

Everything below the `DomainKernel` line is generated per domain by the
Jardis Builder, not provided by this package.

## CLASSES

| Class | Responsibility | Status |
|-------|---------------|--------|
| `DomainKernel` | Immutable, constructor injection, implements `DomainKernelInterface` (13 methods). `container()` → `Factory` | **current** |
| `Bootstrap\BuildDomainKernelFromEnv` | Optional ENV packer. One invokable class (`__invoke(string $projectRoot): DomainKernel`), no static factory | **current** |
| `Bootstrap\LazyAuthServices` | Proxy that builds the auth services on first use (the token store needs the DB connection, resolving it may open it) | **current** |
| `Bootstrap\Handler\*` (15 classes) | Closures the packer composes: `BuildConnectionFromEnv`, `ExtractPdoFromConnection`, `ProvideWriterPdo`, `MemoizeProvider`, `BuildRedisFromEnv` (a lazy `Closure(): Redis` provider), `BuildCacheFromEnv`, `BuildLoggerFromEnv`, `BuildEventListenerProviderFromEnv`, `BuildEventDispatcherFromProvider`, `BuildHttpClientFromEnv`, `BuildMailerFromEnv`, `BuildFilesystemFromEnv`, `BuildMessagingFromEnv`, `BuildAuthServicesFromEnv`; plus normalization helpers `NormalizeEnvBool`, `IsEnvValueUnset` | **current** |
| `Bootstrap\Data\CacheLayer` | ENV enum for `CACHE_LAYERS` — 4 cases: `Memory`, `Apcu`, `Redis`, `Database` | **current** |
| `Bootstrap\Data\LogHandler` | ENV enum for `LOG_HANDLERS` — 11 cases: `File`, `Console`, `ErrorLog`, `Syslog`, `BrowserConsole`, `Redis`, `Slack`, `Teams`, `Loki`, `Webhook`, `Null` | **current** |
| `Bootstrap\Data\CredentialEnvKeySuffixes` | Constant list of credential-shaped ENV key suffixes registered as DotEnv raw keys before `loadPrivate()` | **current** |
| `Exception\InvalidEnvConfigurationException` | Thrown for unparsable bool values and invalid-but-configured settings (pool strategy, `MESSAGING_TRANSPORT`, unreachable configured host) — never for merely absent config | **current** |
| `DomainApp` | Lazy kernel bootstrap, ClassVersion hooks, service sharing, `kernel()`/`handle()` | **deleted** — no replacement class; the generated Domain facade is `final` and holds only the `DomainKernel` |
| `BoundedContext` | Use-case handler base (`handle()`/`context()`, Factory + ClassVersion) | **deleted** — the generated `{Domain}Context` carries this role (`generated-code-extend`) |
| `ServiceRegistry` | Static first-write-wins service sharing | **deleted** — no replacement; sharing is now explicit (same `DomainKernel` instance) |
| `ContextResponse` / `DomainResponse` / `DomainResponseTransformer` / `ResponseStatus` | Response pipeline | **deleted from this package** — generated per domain under `{Domain}\Response\`; `ResponseStatus` moved to `jardissupport/contracts` |

## Eine `.env` im Root (v2.4.0) — what changed

- **One file, one place.** The packer reads `<projectRoot>/.env` plus DotEnv's
  cascade (`.env` → `.env.local` → `.env.{APP_ENV}`, plus any `load()`/
  `load?()` include). There is no `config/` layer any more, and the packer
  **never creates a directory**. A project root without a `.env` is "nothing
  configured": every service `null`, no error.
- **String input, exclusive against the file.**
  `__invoke(string $projectRoot, ?string $envContent = null)`. Pass an
  `.env`-formatted string (e.g. a secrets-manager payload) and the file is not
  read at all, not even per key. **`''` is a valid input** — the dateless
  container, where every value comes from the process environment. The project
  root still matters in string mode: the `support/secret.key` fallback resolves against it. `KEY_FILE=` values are read from disk only when the path is absolute (`jardissupport/dotenv` >= 1.6); a relative `_FILE` value stays a plain string — `COMPOSE_FILE`, `NGINX_INDEX_FILE` and the like are names, not secret mounts.
- **The process environment always wins** (jardissupport/dotenv >= 1.4.0,
  12-factor III) — in both modes, over file and string alike. Note for tests:
  the phpcli image exports `APP_ENV=dev`, which therefore beats a fixture's own
  `APP_ENV`; isolate it (save/restore `APP_ENV`, `APP_SECRET_KEY`,
  `JARDIS_DOTENV_VARS`) or the cascade picks a different overlay than intended.
- **Secret key chain (two steps, in this order).**
  1. `APP_SECRET_KEY` in the **process environment** → `EnvKeyProvider`.
  2. `<projectRoot>/support/secret.key` → `FileKeyProvider`.
  3. Neither → no secret resolution.
  ⚠️ **Henne-Ei:** the master key belongs in the process environment and
  **never in a `.env` file** — a key written into the file would have to be
  read by the very load it is meant to unlock, and it would sit in
  `$kernel->env()` in plaintext. `docs/.env.example` therefore carries
  `APP_SECRET_KEY` only as a comment. The key source is independent of the
  value source: a key file applies to string input too.
- **Unresolved `secret(...)` is loud (O8).** With no key at all, the packer
  does **not** pass the cipher on as a value: `AssertNoUnresolvedSecret` throws
  `JardisCore\Kernel\Exception\InvalidEnvConfigurationException` naming the ENV
  key — **never the value** — before any adapter is built. (Before v2.4.0 the
  cipher reached the handlers verbatim; that is the one behaviour change.)
- **S4' — what may be encrypted.** `secret(...)` is for keys only the kernel
  reads. A key that Docker Compose, `make` or CI also consumes must stay
  plaintext: those readers pass the marker through verbatim, they do not
  decrypt.
- **Template:** `docs/.env.example` — ONE file in eight blocks
  (`# === app ===`, `database`, `redis`, `cache`, `logger`, `http`, `mail`,
  `messaging`). The block syntax is a **contract** the tooling reads (Builder
  runtime catalogue, App-Template parity check), pinned by
  `tests/Unit/Docs/EnvExampleContractTest.php`.
- **Handler units** (all `Bootstrap\Handler\`): `LoadEnvForKernel` (owns the
  string-vs-file decision, wires raw keys + secret handler once),
  `NormalizeEnvKeys`, `ReadEnvValue`, `ReadProcessEnv`,
  `ResolveSecretKeyProvider`, `BuildSecretHandler`, `AssertNoUnresolvedSecret`.
  Their two v2.3 predecessors (the fixed-config-path loader and the
  key-file-only secret builder) are deleted — see CHANGELOG v2.4.0.

## DOMAINKERNEL

```php
use JardisCore\Kernel\DomainKernel;

$kernel = new DomainKernel(
    projectRoot: '/path/to/project',     // required — the git-clone target; its .env is what the packer reads
    container: $factory,                 // ?ContainerInterface
    cache: $cache,                       // ?CacheInterface
    logger: $logger,                     // ?LoggerInterface
    eventDispatcher: $dispatcher,        // ?EventDispatcherInterface
    eventListenerRegistry: $registry,    // ?EventListenerRegistryInterface — Kernel-Entkopplung
    httpClient: $client,                 // ?ClientInterface
    connection: $pool,                   // ConnectionPoolInterface|PDO|null
    mailer: $mailer,                     // ?MailerInterface
    filesystem: $filesystemService,      // ?FilesystemServiceInterface
    env: ['db_host' => 'localhost'],     // array — private ENV, file-only (no $_ENV fallback)
    messaging: $messagingService,        // ?MessagingServiceInterface
    auth: $authServices,                 // ?AuthServicesInterface — last constructor param
);

$ecommerce = new Ecommerce($kernel);     // {Domain} facade, generated by Jardis — no extends
```

| Method | Return | Note |
|--------|--------|------|
| `projectRoot()` | `string` | renamed from `domainRoot()` (env-konfiguration R2/R3) |
| `env(string $key)` | `mixed` | case-insensitive, stored lowercase (`array_change_key_case`); file-only — no `$_ENV` fallback; explicitly empty (`KEY=`) reads as `null` |
| `container()` | `Factory` | always wraps the injected container (not just `ContainerInterface`) |
| `cache()` | `?CacheInterface` | |
| `logger()` | `?LoggerInterface` | |
| `eventDispatcher()` | `?EventDispatcherInterface` | |
| `eventListenerRegistry()` | `?EventListenerRegistryInterface` | **added with Kernel-Entkopplung (D3)** — paired with `eventDispatcher()`, same underlying `ListenerProvider` instance. A generated `{Agg}EventRouter` self-registers on it from the Domain facade's constructor; without a registry, event routing simply stays inactive — no error |
| `httpClient()` | `?ClientInterface` | |
| `dbConnection()` | `ConnectionPoolInterface\|PDO\|null` | |
| `mailer()` | `?MailerInterface` | |
| `filesystem()` | `?FilesystemServiceInterface` | |
| `messaging()` | `?MessagingServiceInterface` | **added with env-konfiguration R4** — publish/consume across kafka/rabbitmq/redis, see dedicated section below |
| `auth()` | `?AuthServicesInterface` | **added with kernel 2.6.0 (contracts >= 2.11)** — `sessions()` / `tokenIssuer()` / `tokenRedeemer()` / `hasher()` from `jardissupport/auth`; packer builds it from `AUTH_TOKEN_KEY`, see "Auth from ENV" below |

`DomainKernel` builds nothing and reads no ENV itself — a pure, immutable
consumer. All 12 services come via constructor; there is no lazy bootstrap,
no `kernel()` hook (that was `DomainApp`'s job — gone).

### Multi-domain sharing is still explicit

```php
$kernel = (new BuildDomainKernelFromEnv())(__DIR__);   // project root; reads <projectRoot>/.env

$ecommerce = new Ecommerce($kernel);   // same DomainKernel instance
$billing   = new Billing($kernel);     // same DomainKernel instance -> same connection, cache, ...
```

A domain that needs its own services builds its own `DomainKernel` instead of
sharing one — there is no static registry to fall back to anymore.

## BOOTSTRAP-PACKER — `BuildDomainKernelFromEnv`

```php
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;

$packer = new BuildDomainKernelFromEnv();
$kernel = $packer(__DIR__);              // project root; reads <projectRoot>/.env (+ cascade)
$kernel = $packer(__DIR__, $payload);    // .env-formatted string instead of the file
$kernel = $packer(__DIR__, '');          // dateless: everything from the process environment

$ecommerce = new Ecommerce($kernel);
```

- **One invokable class**,
  `__invoke(string $projectRoot, ?string $envContent = null): DomainKernel` —
  no `static fromEnv()` (User-Entscheid, D4). `$kernel->projectRoot()` returns
  exactly the project root passed in.
- **ENV cascade** via `JardisSupport\DotEnv\DotEnv::loadPrivate()` (file mode)
  or `loadPrivateFromString()` (string mode) — the same `load()`/`load?()`
  cascade every other Jardis config file understands. Template:
  `docs/.env.example`. Credential-shaped keys
  (`Bootstrap\Data\CredentialEnvKeySuffixes`) are registered as DotEnv raw
  keys before loading, so `DB_PASSWORD=false`/`=123456` reach the handlers as
  literal strings, not cast `bool`/`int`.
- **Redis fan-out (D4):** one Redis connection, built once, feeds both the
  cache `redis` layer and the logger `redis` handler — no duplicated wiring,
  no Redis-specific knowledge in the orchestrator body. `messaging()`'s Redis
  transport builds its **own** separate connections (publisher + consumer) —
  never shares the cache/logger client, a blocking consumer connection must
  not be reused. The **writer PDO fans out the same way**: handed out once as a
  memoizing provider (`ProvideWriterPdo`), it feeds both the optional `db`
  cache layer and the `database` messaging transport — one stack, one
  database. Redis likewise travels as a provider (`BuildRedisFromEnv` returns `?Closure(): Redis`).
- **Every adapter is optional** (composer `suggest`, not required):
  `jardisadapter/{cache,dbconnection,eventdispatcher,filesystem,http,logger,mailer,messaging}` (and `jardissupport/auth` for `auth()`).
  Each Handler closure degrades to `null` via `class_exists()` guards when its
  adapter is missing or its ENV is unconfigured — nothing throws for a missing
  optional service. **Exceptions:** `DB_*` configured (`DB_DRIVER=sqlite` or
  `DB_HOST`) while `jardisadapter/dbconnection` is missing throws
  `InvalidEnvConfigurationException` while building; and a value that IS configured but invalid
  (bad bool, bad pool strategy, unreachable configured Redis host at its first use, invalid
  `MESSAGING_TRANSPORT`) throws `InvalidEnvConfigurationException` — "not
  configured" and "misconfigured" are deliberately different outcomes.
- **Container wiring is out of scope** (G6, "kein Problem, kein Pattern") —
  the packed kernel's `container()` is the bare `Factory` fallback. Need a
  custom PSR-11 container? Build a `DomainKernel` directly instead of going
  through the packer.

### `messaging()` — canonical ENV keys

`MESSAGING_TRANSPORT=kafka|rabbitmq|redis|database`; missing/empty → `null`
(not configured); any other value → `InvalidEnvConfigurationException`. No own
broker-list/port parsing (G13) — `Bootstrap\Handler\BuildMessagingFromEnv`
dispatches straight into `jardisadapter/messaging`'s
`ConnectionFactory`/`PublisherFactory`/`ConsumerFactory`.

| Transport | Required | Optional | Notes |
|---|---|---|---|
| `kafka` | `KAFKA_BROKERS` (comma-separated `host:port` list, unchanged into the factory's host field — `KAFKA_PORT` is not canonical) | `KAFKA_USER`, `KAFKA_PASSWORD` (raw-key protected, `_USER` not `_USERNAME`) | consuming needs a group ID with no canonical key (G14) — `consume()` throws a clear `RuntimeException`; `publish()` is unaffected |
| `rabbitmq` | `RABBITMQ_HOST` | `RABBITMQ_PORT` (5672), `RABBITMQ_USER`/`RABBITMQ_PASSWORD` (guest/guest) | no canonical queue-name key — the `consume(string $topic, ...)` topic doubles as the queue name |
| `redis` | `REDIS_HOST` | `REDIS_PORT` (6379), `REDIS_PASSWORD` | same `REDIS_*` keys as the cache accessor (one stack = one Redis), but its own dedicated connections; uses Streams (`useStreams: true`), not Pub/Sub — measured against a real broker, Pub/Sub loses messages published before a subscriber is listening |
| `database` | a usable `DB_*` connection (no key of its own) | `MESSAGING_DB_TABLE` (`domain_events`), `MESSAGING_DB_SUBSCRIPTION_TABLE` (`domain_event_subscriptions`), `MESSAGING_DB_DELETE_AFTER_PROCESSING` (`false`, via `NormalizeEnvBool`), `MESSAGING_DB_POLLING_INTERVAL_MS` (`1000`), `MESSAGING_DB_BATCH_SIZE` (`10`), `MESSAGING_DB_MAX_ATTEMPTS` (`3`) | broker-less Transactional Outbox; **reuses the writer PDO** the packer already built (`ExtractPdoFromConnection`, same handle the `db` cache layer gets) via `ConnectionFactory::fromDbConnection(provider, manageLifecycle: false)` — no second DSN, no `MESSAGING_DB_DSN` key; `consume()` works (polls the table), Point-to-Point by default, fan-out is a per-call `['group' => …]` option; **tables are a migration, not a kernel task** (adapter ships the MySQL reference schema `src/Schema/domain_events.sql`) |

`database` chosen with no usable writer PDO (no `DB_*` at all) throws
`InvalidEnvConfigurationException` — `MESSAGING_TRANSPORT=database requires a
configured database (DB_*).` — the same line rabbitmq/redis already follow:
degrade-to-null is reserved for an unset `MESSAGING_TRANSPORT`, never for an
explicitly chosen transport whose mandatory config is missing.

**Eager vs. lazy, deliberately not the same boundary:** the connection
OBJECT for the kafka/rabbitmq/redis transport is built eagerly inside the packer — no
network I/O, but `ConnectionConfig`'s own constructor validation (host/port
shape) and this handler's own presence check run immediately, so
`InvalidEnvConfigurationException` surfaces the moment `messaging()` is
first called on the packed kernel, not on first `publish()`. The actual
`connect()`/`publish()`/`consume()` — and therefore any "configured but
unreachable" failure — stays deferred to first use (the adapter's own lazy
design). The `database` transport is lazy one step further: it receives the
writer-PDO provider and hands it to `ConnectionFactory::fromDbConnection()`
(messaging >= 1.1), which resolves it at the first publish/consume, so
`messaging()` opens no database connection.

## THE GENERATED SIDE (not in this package)

Everything downstream of `DomainKernel` is generated per domain by the Jardis
Builder — see `generated-code-extend` for the full contract:

- **`{Domain}Context`** — the generated, hermetic base every BC/Aggregate
  facade in the domain extends. Carries the Kernel-Naht `handle()`/`context()`
  (now `protected` — family-internal only), `resource()`/`payload()`/
  `version()`/`result()`, and `classVersion()`/`classVersionConfig()`.
  `implements JardisSupport\Contract\Kernel\GeneratedContextInterface` (the D5
  marker) — **no `extends BoundedContext`**, no package base class at all.
- **`{Domain}\Response\`** — the generated Response-Trio (`ContextResponse`,
  `DomainResponse`, `DomainResponseTransformer`). `ResponseStatus` itself lives in
  `jardissupport/contracts`.
- **The Domain facade** (e.g. `Ecommerce`) is `final class`, holds only the
  `DomainKernel` (`DomainKernelInterface $kernel`), and self-registers every
  aggregate's event router via `$kernel->eventListenerRegistry()`.

If you are extending or wiring **generated** code, read `generated-code-extend`
/ `generated-code-wire-transport`, not this skill — this package's surface stops at the
`DomainKernel`.

HTTP-Delivery für den DomainKernel (FastRoute-Router, PSR-15-Pipeline, kanonischer
`DomainResponse`→PSR-7-Mapper): siehe `core-app` (`jardiscore/app`).

## RULES
- `DomainKernel` is purely immutable — builds nothing, only consumes. All 11
  services are constructor-injected; there is no post-construction mutation.
- No static shared state anywhere in this package (the former
  `ServiceRegistry` is gone) — sharing across domains means passing the same
  `DomainKernel` instance; isolation means building separate instances.
- `DomainKernel` (+ the contract interfaces it implements) stays adapter-free
  — only `jardissupport/contracts` + PSR interfaces. Adapter imports
  (`jardisadapter/*`) are legitimate **only** inside the `Bootstrap\`
  sub-namespace (Application wiring, not Domain code) — see the
  Constitutional Note in the package README.
- `eventListenerRegistry()` and `eventDispatcher()` always come from the
  **same** underlying provider when built via the packer (D3) — do not wire
  them from two different providers by hand unless you specifically want
  disjoint listener sets.
- Via the packer the database is always a lazy `ConnectionPool`
  (`jardisadapter/dbconnection` ^1.4, required as soon as `DB_*` is configured);
  a plain `PDO` is only for a hand-built `DomainKernel`.
- **Everything is lazy — building the kernel opens nothing external** (Bescheid Rolf 2026-10-15: alle Dienste lazy; `jardisadapter/dbconnection` >= 1.4): no database connection, no SQLite file, no Redis socket. The first access opens, and an error appears there, not at bootstrap. Via the packer mysql/pgsql **and sqlite** are a `ConnectionPool` (single lazy writer without readers; SQLite validates the path and applies its PRAGMAs at the first open); there is no eager rest and no plain-PDO fallback (Entscheid O23 2026-10-16): a configured database (`DB_DRIVER=sqlite` or `DB_HOST`) without `jardisadapter/dbconnection` (^1.4) throws `InvalidEnvConfigurationException` while building — decided from the ENV and `class_exists`, no file, no socket. The `db` cache layer, the `redis` cache layer, a `redis` log handler and the `database` messaging transport get a provider closure (`ProvideWriterPdo`, `BuildRedisFromEnv`) that the adapters take directly (cache/logger/messaging >= 1.1: `CacheDatabase`/`CacheRedis`, `LoggerBuilder::addRedis()`, `ConnectionFactory::fromDbConnection()`) and call at their own first use — the kernel has no lazy proxy of its own for them. Errors at the first access: the `redis` cache layer throws `InvalidEnvConfigurationException`; a `redis` log handler does not throw into the caller (the `Logger` routes the failure to the error handler `BuildLoggerFromEnv` sets, which reports an `InvalidEnvConfigurationException` via `error_log()` as `[BuildLoggerFromEnv] log handler "<id>" failed: ...` and stays silent for any other handler failure; `LogRedis::getRedis()` throws it); the Redis provider keeps its first failure and rethrows the same `InvalidEnvConfigurationException` on every later access without a new `connect` (one connect attempt per kernel); an unopenable database path is the adapter's `RuntimeException` for the cache, and for the `database` messaging transport a `PublishException` (first `publish()`) / `ConsumerException` (first `consume()`) carrying the adapter's message (messaging >= 1.1.1: `ConnectionException` with the adapter error as `previous` at the connection layer). composer `conflict` blocks cache/logger < 1.1, messaging < 1.1.1, dbconnection < 1.4. A configured `dbConnection()` is therefore a `ConnectionPoolInterface` for sqlite too — `instanceof PDO` only holds for a `PDO` you inject into a hand-built `DomainKernel`.
- **Bool-shaped ENV values go through `NormalizeEnvBool`, never a raw `===
  'true'` string compare** — the packer already applies it to every boolean
  Handler key; an unparsable value throws instead of degrading.
- **A configured-but-invalid value throws `InvalidEnvConfigurationException`;
  only an absent one degrades to `null`.** Do not add a new Handler that
  silently swallows a validation failure into the PDO/degraded-`null` path.
- Never reach for `DomainApp`, `BoundedContext`, or `ServiceRegistry` in new
  code — they no longer exist in this package. A generated `{Domain}Context`
  already provides the equivalent surface; consult `generated-code-extend`.

## DEPENDENCIES
```
jardissupport/contracts     ^2.0
jardissupport/classversion  ^1.0
jardissupport/dotenv        ^1.2
jardissupport/factory       ^1.0
psr/container               ^2.0
psr/log                     ^3.0
psr/simple-cache            ^3.0
psr/event-dispatcher        ^1.0
psr/http-client             ^1.0
# suggest (used by Bootstrap\BuildDomainKernelFromEnv, degrades to null when absent):
# jardisadapter/{cache,dbconnection,eventdispatcher,filesystem,http,logger,mailer,messaging}
# ext-redis, ext-rdkafka, ext-amqp
```

## Auth from ENV (v2.6.0)

`auth()` returns `?AuthServicesInterface` (contracts >= 2.11, implemented by
`jardissupport/auth` >= 1.6 `AuthServices`): sessions (signed access token +
opaque refresh token), token issuer/redeemer, password hasher.

- **Key:** `AUTH_TOKEN_KEY` = base64 of 32 raw bytes (same decode the generated
  `middleware.php` applies). **Without the key, or without `jardissupport/auth`
  installed, `auth()` is `null`** — nothing throws. A key that is set but not
  base64 / not 32 bytes, or set without a configured database, throws
  `InvalidEnvConfigurationException` naming `AUTH_TOKEN_KEY`.
- **Store:** `DbTokenStore` on the SAME connection as `dbConnection()` — a pool
  contributes its writer (via the packer SQLite is a pool too); a bare PDO —
  only in a hand-built `DomainKernel` — is wrapped via
  `jardisadapter/dbconnection` `ConnectionFactory::fromPdo(manageLifecycle: false)`
  (the bridge the messaging transport uses too). The token table DDL ships with
  the auth package (`resources/sql/<driver>.sql`), applied once per project.
- **Lazy:** the packer returns `LazyAuthServices`; holding or null-checking
  `$kernel->auth()` opens no connection. The first service call resolves the
  writer and builds the store.
- Rotating the key invalidates every issued access token. Put the key in the
  process environment or the project `.env` (no `secret(...)` needed, but fine).
