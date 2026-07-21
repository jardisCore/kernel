---
name: core-kernel
description: jardiscore/kernel v2 - the Koffer DomainKernel (immutable, 11 accessors incl. eventListenerRegistry) plus the optional Bootstrap-Packer BuildDomainKernelFromEnv. DomainApp/BoundedContext/ServiceRegistry/Response pipeline were removed (Kernel-Entkopplung 2026-07) - the generated {Domain}Context now carries handle()/context()/resource()/payload()/version()/result(). TRIGGER: DomainKernel, DomainKernelInterface, Koffer, BuildDomainKernelFromEnv, Bootstrap-Packer, eventListenerRegistry. Formerly (now generated, not in this package): DomainApp, BoundedContext, ServiceRegistry, SharedRegistry, ContextResponse, DomainResponse.
user-invocable: false
zone: post-active
persona: C
prerequisites: [rules-architecture, rules-patterns]
next: [platform-implementation, core-app]
---

# KERNEL_COMPONENT_SKILL
> `jardiscore/kernel` | NS: `JardisCore\Kernel` | PHP 8.2+

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
- **Shared vocabulary moved to `jardissupport/contract` v2:** `ResponseStatus`
  (enum), `GeneratedContextInterface` (the D5 marker every generated
  `{Domain}Context` implements), `DomainKernelInterface` (11 methods,
  `eventListenerRegistry()` added).
- **This package keeps exactly two things:** the immutable Koffer
  (`DomainKernel`, implements `DomainKernelInterface`) and an optional
  ENV-driven packer (`Bootstrap\BuildDomainKernelFromEnv`) that builds one.
  Generated domains take the Koffer via constructor injection —
  `new {Domain}($kernel)` — nothing extends anything in this package anymore.
- **No more static shared state.** `ServiceRegistry`'s first-write-wins
  sharing is gone (G11) — sharing services across domains is now explicit:
  pass the **same** `DomainKernel` instance to every domain facade that should
  share it; build a separate Koffer for a domain that needs its own.

## ARCHITECTURE

```
Bootstrap\BuildDomainKernelFromEnv (optional ENV packer, one invokable class)
    → packs
DomainKernel implements DomainKernelInterface (the "Koffer" — immutable,
                                                constructor injection, 11 accessors)
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
| `DomainKernel` | The Koffer. Immutable, constructor injection, implements `DomainKernelInterface` (11 methods). `container()` → `Factory` | **current** |
| `Bootstrap\BuildDomainKernelFromEnv` | Optional ENV packer. One invokable class (`__invoke(string $configPath): DomainKernel`), no static factory | **current** |
| `Bootstrap\Handler\*` (10 classes) | Closures the packer composes: `BuildConnectionFromEnv`, `ExtractPdoFromConnection`, `BuildRedisFromEnv`, `BuildCacheFromEnv`, `BuildLoggerFromEnv`, `BuildEventListenerProviderFromEnv`, `BuildEventDispatcherFromProvider`, `BuildHttpClientFromEnv`, `BuildMailerFromEnv`, `BuildFilesystemFromEnv` | **current** |
| `Bootstrap\Data\CacheLayer` | ENV enum for `CACHE_LAYERS` — 4 cases: `Memory`, `Apcu`, `Redis`, `Database` | **current** |
| `Bootstrap\Data\LogHandler` | ENV enum for `LOG_HANDLERS` — 11 cases: `File`, `Console`, `ErrorLog`, `Syslog`, `BrowserConsole`, `Redis`, `Slack`, `Teams`, `Loki`, `Webhook`, `Null` | **current** |
| `DomainApp` | Lazy kernel bootstrap, ClassVersion hooks, service sharing, `kernel()`/`handle()` | **deleted** — no replacement class; the generated Domain facade is `final` and holds only the Koffer |
| `BoundedContext` | Use-case handler base (`handle()`/`context()`, Factory + ClassVersion) | **deleted** — ported 1:1 into the generated `{Domain}Context` (`platform-implementation`) |
| `ServiceRegistry` | Static first-write-wins service sharing | **deleted** — no replacement; sharing is now explicit (same Koffer instance) |
| `ContextResponse` / `DomainResponse` / `DomainResponseTransformer` / `ResponseStatus` | Response pipeline | **deleted from this package** — generated per domain under `{Domain}\Response\`; `ResponseStatus` moved to `jardissupport/contract` v2 |

## DOMAINKERNEL — the Koffer

```php
use JardisCore\Kernel\DomainKernel;

$kernel = new DomainKernel(
    domainRoot: '/path/to/config',       // required
    container: $factory,                 // ?ContainerInterface
    cache: $cache,                       // ?CacheInterface
    logger: $logger,                     // ?LoggerInterface
    eventDispatcher: $dispatcher,        // ?EventDispatcherInterface
    eventListenerRegistry: $registry,    // ?EventListenerRegistryInterface — NEW in v2
    httpClient: $client,                 // ?ClientInterface
    connection: $pool,                   // ConnectionPoolInterface|PDO|null
    mailer: $mailer,                     // ?MailerInterface
    filesystem: $filesystemService,      // ?FilesystemServiceInterface
    env: ['db_host' => 'localhost'],     // array — private ENV, takes precedence over $_ENV
);

$ecommerce = new Ecommerce($kernel);     // {Domain} facade, generated by Jardis — no extends
```

| Method | Return | Note |
|--------|--------|------|
| `domainRoot()` | `string` | |
| `env(string $key)` | `mixed` | case-insensitive; private ENV > `$_ENV`, stored lowercase (`array_change_key_case`) |
| `container()` | `Factory` | always wraps the injected container (not just `ContainerInterface`) |
| `cache()` | `?CacheInterface` | |
| `logger()` | `?LoggerInterface` | |
| `eventDispatcher()` | `?EventDispatcherInterface` | |
| `eventListenerRegistry()` | `?EventListenerRegistryInterface` | **new in v2 (D3)** — paired with `eventDispatcher()`, same underlying `ListenerProvider` instance. A generated `{Agg}EventRouter` self-registers on it from the Domain facade's constructor; without a registry in the Koffer, event routing simply stays inactive — no error |
| `httpClient()` | `?ClientInterface` | |
| `dbConnection()` | `ConnectionPoolInterface\|PDO\|null` | |
| `mailer()` | `?MailerInterface` | |
| `filesystem()` | `?FilesystemServiceInterface` | |

`DomainKernel` builds nothing and reads no ENV itself — a pure, immutable
consumer. All 11 services come via constructor; there is no lazy bootstrap,
no `kernel()` hook (that was `DomainApp`'s job — gone).

### Multi-domain sharing is now explicit

```php
$kernel = (new BuildDomainKernelFromEnv())(__DIR__ . '/config');

$ecommerce = new Ecommerce($kernel);   // same Koffer instance
$billing   = new Billing($kernel);     // same Koffer instance -> same connection, cache, ...
```

A domain that needs its own services builds its own Koffer instead of
sharing one — there is no static registry to fall back to anymore.

## BOOTSTRAP-PACKER — `BuildDomainKernelFromEnv`

```php
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;

$packer = new BuildDomainKernelFromEnv();
$kernel = $packer(__DIR__ . '/config');   // reads config/.env (+ cascade) via DotEnv::loadPrivate()

$ecommerce = new Ecommerce($kernel);
```

- **One invokable class**, `__invoke(string $configPath): DomainKernel` — no
  `static fromEnv()` (User-Entscheid, D4). `$configPath` doubles as the packed
  kernel's `domainRoot()`.
- **ENV cascade** via `JardisSupport\DotEnv\DotEnv::loadPrivate()` — the same
  `load()`/`load?()` cascade every other Jardis config file understands.
  Templates: `docs/env-examples/`.
- **Composes 10 Handler closures** in the constructor (`(new Handler())->
  __invoke(...)` — Closure-Orchestrator, no eager business logic in
  `BuildDomainKernelFromEnv::__invoke()` itself beyond wiring the return
  values together): `BuildConnectionFromEnv`, `ExtractPdoFromConnection`,
  `BuildRedisFromEnv`, `BuildCacheFromEnv`, `BuildLoggerFromEnv`,
  `BuildEventListenerProviderFromEnv`, `BuildEventDispatcherFromProvider`,
  `BuildHttpClientFromEnv`, `BuildMailerFromEnv`, `BuildFilesystemFromEnv`.
  Plus a non-Handler `loadEnv` closure (`DotEnv::loadPrivate(...)`) —
  **11 `private readonly Closure` properties in total** on the class.
- **Redis fan-out (D4):** one Redis connection, built once, feeds both the
  cache `redis` layer and the logger `redis` handler via named sub-closures
  — no duplicated wiring, no Redis-specific knowledge in the orchestrator body.
- **Event dispatcher + registry are a pair (D3):** `BuildEventListenerProviderFromEnv`
  builds one `ListenerProvider`; `BuildEventDispatcherFromProvider` wraps that
  **same instance** for `eventDispatcher()`, and it is passed unchanged as
  `eventListenerRegistry()` — a generated `{Agg}EventRouter` registering on
  the registry is visible to the dispatcher.
- **Every adapter is optional** (composer `suggest`, not required):
  `jardisadapter/{cache,dbconnection,eventdispatcher,filesystem,http,logger,mailer}`.
  Each Handler closure degrades to `null` via `class_exists()` guards when its
  adapter is missing or its ENV is unconfigured — nothing throws for a missing
  optional service.
- **Container wiring is out of scope** (G6, "kein Problem, kein Pattern") —
  the packed kernel's `container()` is the bare `Factory` fallback. Need a
  custom PSR-11 container? Build a `DomainKernel` directly instead of going
  through the packer.

## THE GENERATED SIDE (not in this package)

Everything downstream of the Koffer is generated per domain by the Jardis
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
  `jardissupport/contract` v2.
- **The Domain facade** (e.g. `Ecommerce`) is `final class`, holds only the
  Koffer (`DomainKernelInterface $kernel`), and self-registers every
  aggregate's event router via `$kernel->eventListenerRegistry()`.

If you are extending or wiring **generated** code, read `platform-implementation`
/ `platform-usage`, not this skill — this package's surface stops at the Koffer.

HTTP-Delivery für den Koffer (FastRoute-Router, PSR-15-Pipeline, kanonischer
`DomainResponse`→PSR-7-Mapper): siehe `core-app` (`jardiscore/app`).

## RULES
- `DomainKernel` is purely immutable — builds nothing, only consumes. All 11
  services are constructor-injected; there is no post-construction mutation.
- No static shared state anywhere in this package (the former
  `ServiceRegistry` is gone) — sharing across domains means passing the same
  `DomainKernel` instance; isolation means building separate instances.
- The Koffer core (`DomainKernel` + the contract interfaces it implements)
  stays adapter-free — only `jardissupport/contract` + PSR interfaces. Adapter
  imports (`jardisadapter/*`) are legitimate **only** inside the `Bootstrap\`
  sub-namespace (Application wiring, not Domain code) — see the
  Constitutional Note in the package README.
- `eventListenerRegistry()` and `eventDispatcher()` always come from the
  **same** underlying provider when built via the packer (D3) — do not wire
  them from two different providers by hand unless you specifically want
  disjoint listener sets.
- Prefer plain `PDO`; `ConnectionPool` (`jardisadapter/dbconnection`) only
  when read replicas or health-checks are needed.
- Never reach for `DomainApp`, `BoundedContext`, or `ServiceRegistry` in new
  code — they no longer exist in this package. A generated `{Domain}Context`
  already provides the equivalent surface; consult `platform-implementation`.

## DEPENDENCIES
```
jardissupport/contract      ^2.0
jardissupport/classversion  ^1.0
jardissupport/dotenv        ^1.0
jardissupport/factory       ^1.0
psr/container               ^2.0
psr/log                     ^3.0
psr/simple-cache            ^3.0
psr/event-dispatcher        ^1.0
psr/http-client             ^1.0
# suggest (used by Bootstrap\BuildDomainKernelFromEnv, degrades to null when absent):
# jardisadapter/{cache,dbconnection,eventdispatcher,filesystem,http,logger,mailer}, ext-redis
```
