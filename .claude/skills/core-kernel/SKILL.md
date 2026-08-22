---
name: core-kernel
description: jardiscore/kernel v2 - the immutable DomainKernel (11 nullable/typed accessors + container(), incl. eventListenerRegistry, messaging) plus the optional Bootstrap-Packer BuildDomainKernelFromEnv (projectRoot-based, `<projectRoot>/config/env` convention). DomainApp/BoundedContext/ServiceRegistry/Response pipeline were removed (Kernel-Entkopplung 2026-07) - the generated {Domain}Context now carries handle()/context()/resource()/payload()/version()/result(). TRIGGER: DomainKernel, DomainKernelInterface, projectRoot, BuildDomainKernelFromEnv, Bootstrap-Packer, eventListenerRegistry, messaging(). Formerly (now generated, not in this package): DomainApp, BoundedContext, ServiceRegistry, SharedRegistry, ContextResponse, DomainResponse.
user-invocable: false
zone: post-active
persona: C
prerequisites: [rules-architecture, rules-patterns]
next: [platform-implementation, core-app]
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
  the `platform-implementation` skill for that generated-code contract — this
  package does **not** define those classes anymore.
- **Shared vocabulary moved to `jardissupport/contracts`:** `ResponseStatus`
  (enum), `GeneratedContextInterface` (the D5 marker every generated
  `{Domain}Context` implements), `DomainKernelInterface` (12 methods incl.
  `container()`, `eventListenerRegistry()` and `messaging()`).
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
  now takes the **project root**, not a config path — the fixed convention is
  `<projectRoot>/config/env` (created via race-safe `mkdir` if missing; an
  `mkdir` failure is the only case that throws, an empty/freshly-created
  directory degrades every service to `null` like any other unconfigured
  ENV). `DomainKernel::projectRoot()` returns exactly the path passed to the
  packer's `__invoke()`, never the internal `config/env` path.
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
- **Invalid config no longer disappears into the PDO fallback.**
  `InvalidEnvConfigurationException` is rethrown from
  `BuildConnectionFromEnv`/`BuildRedisFromEnv`'s catch-`\Throwable` paths — a
  typo in `DB_POOL_LOAD_BALANCING_STRATEGY` or an unreachable **configured**
  host now fails loudly instead of silently degrading (missing config still
  degrades to `null`, that distinction is intentional).
- **Credential-shaped ENV keys survive the cast chain as strings.**
  `Bootstrap\Data\CredentialEnvKeySuffixes` (`_PASSWORD`, `_USER`, `_SECRET`,
  `_TOKEN`) are registered via `DotEnv::addRawKeys()` before `loadPrivate()`
  — `DB_PASSWORD=false`/`=123456` reach the handlers as the literal string,
  not a cast `bool`/`int`.
- **`messaging()` accessor, real (not just container-reachable).** New
  12th method on `DomainKernelInterface`; the packer's
  `Bootstrap\Handler\BuildMessagingFromEnv` bootstraps it eagerly from
  canonical ENV keys (see dedicated section below) — see the
  G11-accessor rule in `[[domainkernel-ist-die-infrastrukturflaeche]]`
  (wissensbasis): a named accessor exists exactly when the kernel itself
  bootstraps the service from canonical ENV keys.

## ARCHITECTURE

```
Bootstrap\BuildDomainKernelFromEnv (optional ENV packer, one invokable class)
    → packs
DomainKernel implements DomainKernelInterface (immutable,
                                                constructor injection, 12 methods)
    ↓ passed to
new {Domain}($kernel)               ← generated Domain facade (final, JardisCore-free)
    ↓ new {BC}($kernel)              ← generated BC facade, extends {Domain}Context
{Domain}Context                     ← GENERATED per domain (platform-implementation skill)
    handle()/context() (Kernel-Naht, protected) · resource()/payload()/version()/result()
    ↓ result()
ContextResponse (GENERATED) → DomainResponseTransformer (GENERATED) → DomainResponse (GENERATED)
```

Everything below the `DomainKernel` line is generated per domain by the
Jardis Builder, not provided by this package.

## CLASSES

| Class | Responsibility | Status |
|-------|---------------|--------|
| `DomainKernel` | Immutable, constructor injection, implements `DomainKernelInterface` (12 methods). `container()` → `Factory` | **current** |
| `Bootstrap\BuildDomainKernelFromEnv` | Optional ENV packer. One invokable class (`__invoke(string $projectRoot): DomainKernel`), no static factory | **current** |
| `Bootstrap\Handler\*` (11 classes) | Closures the packer composes: `BuildConnectionFromEnv`, `ExtractPdoFromConnection`, `BuildRedisFromEnv`, `BuildCacheFromEnv`, `BuildLoggerFromEnv`, `BuildEventListenerProviderFromEnv`, `BuildEventDispatcherFromProvider`, `BuildHttpClientFromEnv`, `BuildMailerFromEnv`, `BuildFilesystemFromEnv`, `BuildMessagingFromEnv`; plus normalization helpers `NormalizeEnvBool`, `IsEnvValueUnset` | **current** |
| `Bootstrap\Data\CacheLayer` | ENV enum for `CACHE_LAYERS` — 4 cases: `Memory`, `Apcu`, `Redis`, `Database` | **current** |
| `Bootstrap\Data\LogHandler` | ENV enum for `LOG_HANDLERS` — 11 cases: `File`, `Console`, `ErrorLog`, `Syslog`, `BrowserConsole`, `Redis`, `Slack`, `Teams`, `Loki`, `Webhook`, `Null` | **current** |
| `Bootstrap\Data\CredentialEnvKeySuffixes` | Constant list of credential-shaped ENV key suffixes registered as DotEnv raw keys before `loadPrivate()` | **current** |
| `Exception\InvalidEnvConfigurationException` | Thrown for unparsable bool values and invalid-but-configured settings (pool strategy, `MESSAGING_TRANSPORT`, unreachable configured host) — never for merely absent config | **current** |
| `DomainApp` | Lazy kernel bootstrap, ClassVersion hooks, service sharing, `kernel()`/`handle()` | **deleted** — no replacement class; the generated Domain facade is `final` and holds only the `DomainKernel` |
| `BoundedContext` | Use-case handler base (`handle()`/`context()`, Factory + ClassVersion) | **deleted** — ported 1:1 into the generated `{Domain}Context` (`platform-implementation`) |
| `ServiceRegistry` | Static first-write-wins service sharing | **deleted** — no replacement; sharing is now explicit (same `DomainKernel` instance) |
| `ContextResponse` / `DomainResponse` / `DomainResponseTransformer` / `ResponseStatus` | Response pipeline | **deleted from this package** — generated per domain under `{Domain}\Response\`; `ResponseStatus` moved to `jardissupport/contracts` |

## DOMAINKERNEL

```php
use JardisCore\Kernel\DomainKernel;

$kernel = new DomainKernel(
    projectRoot: '/path/to/project',     // required — the git-clone target, NOT config/env itself
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
    messaging: $messagingService,        // ?MessagingServiceInterface — last constructor param
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

`DomainKernel` builds nothing and reads no ENV itself — a pure, immutable
consumer. All 11 services come via constructor; there is no lazy bootstrap,
no `kernel()` hook (that was `DomainApp`'s job — gone).

### Multi-domain sharing is still explicit

```php
$kernel = (new BuildDomainKernelFromEnv())(__DIR__);   // project root, not config/env

$ecommerce = new Ecommerce($kernel);   // same DomainKernel instance
$billing   = new Billing($kernel);     // same DomainKernel instance -> same connection, cache, ...
```

A domain that needs its own services builds its own `DomainKernel` instead of
sharing one — there is no static registry to fall back to anymore.

## BOOTSTRAP-PACKER — `BuildDomainKernelFromEnv`

```php
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;

$packer = new BuildDomainKernelFromEnv();
$kernel = $packer(__DIR__);   // project root; reads <projectRoot>/config/env (+ cascade) via DotEnv::loadPrivate()

$ecommerce = new Ecommerce($kernel);
```

- **One invokable class**, `__invoke(string $projectRoot): DomainKernel` — no
  `static fromEnv()` (User-Entscheid, D4). Takes the **project root**, not a
  config path (env-konfiguration R2/R3) — the fixed convention is
  `<projectRoot>/config/env`, created via race-safe `mkdir` if missing.
  `$kernel->projectRoot()` returns exactly the project root passed in, never
  the internal `config/env` path.
- **ENV cascade** via `JardisSupport\DotEnv\DotEnv::loadPrivate()` — the same
  `load()`/`load?()` cascade every other Jardis config file understands.
  Templates: `docs/env-examples/`. Credential-shaped keys
  (`Bootstrap\Data\CredentialEnvKeySuffixes`) are registered as DotEnv raw
  keys before loading, so `DB_PASSWORD=false`/`=123456` reach the handlers as
  literal strings, not cast `bool`/`int`.
- **Redis fan-out (D4):** one Redis connection, built once, feeds both the
  cache `redis` layer and the logger `redis` handler — no duplicated wiring,
  no Redis-specific knowledge in the orchestrator body. `messaging()`'s Redis
  transport builds its **own** separate connections (publisher + consumer) —
  never shares the cache/logger client, a blocking consumer connection must
  not be reused.
- **Every adapter is optional** (composer `suggest`, not required):
  `jardisadapter/{cache,dbconnection,eventdispatcher,filesystem,http,logger,mailer,messaging}`.
  Each Handler closure degrades to `null` via `class_exists()` guards when its
  adapter is missing or its ENV is unconfigured — nothing throws for a missing
  optional service. **Exception:** a value that IS configured but invalid
  (bad bool, bad pool strategy, unreachable configured host, invalid
  `MESSAGING_TRANSPORT`) throws `InvalidEnvConfigurationException` — "not
  configured" and "misconfigured" are deliberately different outcomes.
- **Container wiring is out of scope** (G6, "kein Problem, kein Pattern") —
  the packed kernel's `container()` is the bare `Factory` fallback. Need a
  custom PSR-11 container? Build a `DomainKernel` directly instead of going
  through the packer.

### `messaging()` — canonical ENV keys

`MESSAGING_TRANSPORT=kafka|rabbitmq|redis`; missing/empty → `null` (not
configured); any other value → `InvalidEnvConfigurationException`. No own
broker-list/port parsing (G13) — `Bootstrap\Handler\BuildMessagingFromEnv`
dispatches straight into `jardisadapter/messaging`'s
`ConnectionFactory`/`PublisherFactory`/`ConsumerFactory`.

| Transport | Required | Optional | Notes |
|---|---|---|---|
| `kafka` | `KAFKA_BROKERS` (comma-separated `host:port` list, unchanged into the factory's host field — `KAFKA_PORT` is not canonical) | `KAFKA_USER`, `KAFKA_PASSWORD` (raw-key protected, `_USER` not `_USERNAME`) | consuming needs a group ID with no canonical key (G14) — `consume()` throws a clear `RuntimeException`; `publish()` is unaffected |
| `rabbitmq` | `RABBITMQ_HOST` | `RABBITMQ_PORT` (5672), `RABBITMQ_USER`/`RABBITMQ_PASSWORD` (guest/guest) | no canonical queue-name key — the `consume(string $topic, ...)` topic doubles as the queue name |
| `redis` | `REDIS_HOST` | `REDIS_PORT` (6379), `REDIS_PASSWORD` | same `REDIS_*` keys as the cache accessor (one stack = one Redis), but its own dedicated connections; uses Streams (`useStreams: true`), not Pub/Sub — measured against a real broker, Pub/Sub loses messages published before a subscriber is listening |

**Eager vs. lazy, deliberately not the same boundary:** the connection
OBJECT for the chosen transport is built eagerly inside the packer — no
network I/O, but `ConnectionConfig`'s own constructor validation (host/port
shape) and this handler's own presence check run immediately, so
`InvalidEnvConfigurationException` surfaces the moment `messaging()` is
first called on the packed kernel, not on first `publish()`. The actual
`connect()`/`publish()`/`consume()` — and therefore any "configured but
unreachable" failure — stays deferred to first use (the adapter's own lazy
design).

## THE GENERATED SIDE (not in this package)

Everything downstream of `DomainKernel` is generated per domain by the Jardis
Builder — see `platform-implementation` for the full contract:

- **`{Domain}Context`** — the generated, hermetic base every BC/Aggregate
  facade in the domain extends. Carries the Kernel-Naht `handle()`/`context()`
  (now `protected` — family-internal only), `resource()`/`payload()`/
  `version()`/`result()`, and `classVersion()`/`classVersionConfig()`.
  `implements JardisSupport\Contract\Kernel\GeneratedContextInterface` (the D5
  marker) — **no `extends BoundedContext`**, no package base class at all.
- **`{Domain}\Response\`** — the generated Response-Trio (`ContextResponse`,
  `DomainResponse`, `DomainResponseTransformer`), 1:1-ported from this
  package's former `src/Response/*`. `ResponseStatus` itself lives in
  `jardissupport/contracts`.
- **The Domain facade** (e.g. `Ecommerce`) is `final class`, holds only the
  `DomainKernel` (`DomainKernelInterface $kernel`), and self-registers every
  aggregate's event router via `$kernel->eventListenerRegistry()`.

If you are extending or wiring **generated** code, read `platform-implementation`
/ `platform-usage`, not this skill — this package's surface stops at the
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
- Prefer plain `PDO`; `ConnectionPool` (`jardisadapter/dbconnection`) only
  when read replicas or health-checks are needed.
- **Bool-shaped ENV values go through `NormalizeEnvBool`, never a raw `===
  'true'` string compare** — the packer already applies it to every boolean
  Handler key; an unparsable value throws instead of degrading.
- **A configured-but-invalid value throws `InvalidEnvConfigurationException`;
  only an absent one degrades to `null`.** Do not add a new Handler that
  silently swallows a validation failure into the PDO/degraded-`null` path.
- Never reach for `DomainApp`, `BoundedContext`, or `ServiceRegistry` in new
  code — they no longer exist in this package. A generated `{Domain}Context`
  already provides the equivalent surface; consult `platform-implementation`.

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
