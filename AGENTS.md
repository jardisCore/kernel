# jardiscore/kernel

Application-layer offering (Kernel-Entkopplung 2026-07): the immutable service case `DomainKernel` (Constructor Injection, implements `JardisSupport\Contract\Kernel\DomainKernelInterface`) plus the ENV packer `Bootstrap\BuildDomainKernelFromEnv`. The former domain-side classes (`DomainApp`, `ServiceRegistry`, `BoundedContext`, `Response/*`) were removed — the Jardis Builder now generates the context body and the response trio into each domain (`{Domain}\Response\`); the shared vocabulary (`ResponseStatus`, `GeneratedContextInterface` marker) lives in `jardissupport/contracts`.

## Usage essentials

- **`DomainKernel` is a pure consumer** — immutable, builds nothing itself, loads no ENV. All 11 services come via constructor; `env(string $key)` is case-insensitive (private ENV > `$_ENV`, stored lowercase internally via `array_change_key_case`). `container()` always returns **`Factory`** (not just `ContainerInterface`) — Factory wraps an external container and provides `create()` in addition to PSR-11 `get()`/`has()`. Added with Kernel-Entkopplung: `eventListenerRegistry(): ?EventListenerRegistryInterface` — dispatcher + registry are handed in as a pair built from the same `ListenerProvider` instance.
- **The kernel core stays adapter-free** (Contract + PSR imports only). Adapter imports are legitimate exclusively inside the `Bootstrap\` sub-namespace — after the Kernel-Entkopplung, jardiscore/kernel is application layer, outside the inward-pointing hexagonal arrows (see README, constitutional note).
- **`Bootstrap\BuildDomainKernelFromEnv`** is ONE invokable class (`__invoke(string $configPath): DomainKernel`), no static factory. It loads the ENV cascade via `DotEnv::loadPrivate()` and composes 10 handler closures (cache, connection, dispatcher/listener-provider pair, filesystem, http, logger, mailer, redis, PDO extraction); the Redis fan-out (Redis feeds logger AND cache) is split into named sub-closures. Adapters are `suggest`/`require-dev` — missing adapters yield `null` services, never errors. `.env` templates: `docs/env-examples/`.
- **Consuming a generated domain:** `new {Domain}($kernel)` — the generated facade takes the case via constructor (`DomainKernelInterface`); one packer call per app bootstraps all domains.

## Full reference

https://docs.jardis.io/en/core/kernel

<!-- BEGIN jardis/dev-skills — managed block, do not edit by hand -->

# Jardis packages — AI agent context

Aggregated by `jardis/dev-skills`. Run `composer install` to refresh.

Before hand-building a reusable building block, consult the `jardis-catalog` skill to check for an installable Jardis package. For the full workflow from schema to implementation, start with the `jardis-start-here` skill.

<!-- source: jardis/dev-skills -->
# jardis/dev-skills — Agent Notes

Composer plugin that distributes Jardis skills (`<vendor>/.claude/skills/<name>/SKILL.md`) and aggregates `AGENTS.md` from Jardis vendor packages into the consumer project.

## What this package contributes

- **Discovery** of skills from `vendor/jardis*/*/.claude/skills/*/SKILL.md` and from this repo's own `skills/` directory.
- **Bundle skills** (opt-in via `extra."jardis/dev-skills"."bundled-skills"`) covering Jardis methodology:
  - `schema-authoring` — pre-Designer Schema.yaml authoring (companion `examples/Schema.yaml`)
  - `platform-implementation` — extending Designer-generated PHP code (Extensions/ layout, ClassVersion v2 override mechanics, V1–V12 prohibitions)
  - `platform-usage` — wiring Designer-generated Commands/Queries into a transport (HTTP / CLI / queue / worker), DomainResponse mapping
  - `platform-versioning` — ClassVersion resolution chain + the Versionierungs-Modell for Designer-generated code
  - `platform-workflow` — Workflow-Engine API consumed by FlowDesigner-generated Use-Case orchestrators
  - `platform-cookbook` — Phase-3 recipes, troubleshooting, and event transport for Designer-generated code
  - `rules-architecture` / `rules-frontend` / `rules-patterns` / `rules-testing` — cross-cutting rules (`rules-frontend` = stack-agnostic FE review constitution)
- **Managed prefixes:** `adapter-`, `core-`, `support-`, `tools-`, `schema-`, `plan-`, `platform-`, `rules-`. Skills with these prefixes are installed/removed by the plugin; skills without them belong to the user.
- **AGENTS.md aggregation** between markers `<!-- BEGIN jardis/dev-skills ... -->` / `<!-- END jardis/dev-skills -->`. User content outside the markers is preserved. A source package's own managed block is stripped before embedding (`Handler/Install/StripManagedBlock`), so the result is always a single, non-nested block. `*.backup` skill directories are skipped during discovery and never re-backed-up.

## Working in this repo

- **Architecture:** Closure-Orchestrator — `src/SkillInstaller.php` and `src/SkillUninstaller.php` compose handlers from `src/Handler/`. Data classes under `src/Data/`. No business logic in orchestrators.
- **Plugin entry:** `src/Plugin.php` (`Composer\Plugin\PluginInterface` + `EventSubscriberInterface`) wires `post-install-cmd`, `post-update-cmd`, `pre-package-uninstall`.
- **Tests:** Integration > Unit. New tests go under `tests/Integration/<area>/<ClassName>Test.php`. Use `tests/Support/TempProject` for filesystem fixtures.
- **Quality gates:** `make phpunit` (150+ tests), `make phpstan` (Level 8), `make phpcs` (PSR-12). All three must be green.
- **Skill authoring:** Every bundled `SKILL.md` follows `docs/SKILL-FORMAT.md` v3 — frontmatter `zone`/`prerequisites`/`next`, single-line description (≤60 words), topical numbered body sections (`### 1. …`), per-zone line budget (`post-active` = 550). Long working artefacts live in a sibling `skills/<name>/examples/` directory and do not count against the body budget. Reshape rationale in `docs/PRD-skill-overhaul.md`.

## Don'ts

- Do not introduce a new top-level skill prefix without updating `RemoveJardisSkills::MANAGED_PREFIXES` and `docs/SKILL-FORMAT.md` §2.
- Do not edit a generated AGENTS.md block in a consumer project — the plugin overwrites it on next install.
- Do not bypass `TempProject` in tests with raw `tempnam()` / hardcoded paths.
- Do not duplicate content across bundle skills. Patterns live only in `rules-patterns`, architecture only in `rules-architecture`, frontend review rules only in `rules-frontend`, test rules only in `rules-testing`, generated-code layout only in `platform-implementation` §1, transport wiring only in `platform-usage`. Designer YAML vocabulary (Aggregate / Source / FieldMap / Lists / Flow) lives in `tools-builder-engine` in the Builder repo — outside this bundle. Other skills link.

## Pointers

- README (consumer-facing): `README.md`
- Skill format spec: `docs/SKILL-FORMAT.md`
- Skill format validator: `bin/validate-skills.php` (run via `make validate-skills`)
- Bundle overhaul rationale: `docs/PRD-skill-overhaul.md`, `docs/PLAN-skill-overhaul.md`

<!-- source: jardisadapter/cache -->
# jardisadapter/cache

PSR-16 multi-layer cache with Chain of Responsibility: `get()` runs L1 → L2 → L3 and auto-populates higher layers on hit (write-through).

## Usage essentials

- **Immutable:** All layers are passed via the constructor (`new Cache([...])`). No `addCache()`, no runtime changes.
- **Purpose-Built:** Build a separate `Cache` instance per use case (e.g. `$requestCache`, `$sessionCache`). No string-based layer handling.
- **`set()`/`delete()`/`clear()` affect ALL layers;** `get()` stops at the first hit and populates backwards.
- **Namespace per adapter, not per cache:** `new CacheRedis($redis, 'myapp')`. `Cache` itself has no namespace — it trusts its layers.
- **Safe Default:** `new Cache()` without layers uses an internal `CacheNull` — all ops are no-op (no bootstrapping needed).
- **Graceful Degradation:** `RedisException`/`PDOException` are caught → returns `$default`/`false`. Exception: an empty/whitespace key throws `InvalidArgumentException` (propagates outward, PSR-16 compliant).

## Full reference

https://docs.jardis.io/en/adapter/cache

<!-- source: jardisadapter/dbconnection -->
# jardisadapter/dbconnection

PDO connection management for MySQL/MariaDB, PostgreSQL, and SQLite with `ConnectionPool` for read/write splitting, load balancing, and failover.

## Usage Essentials

- **`ConnectionFactory` is the single entry point** — `new ConnectionFactory()->mysql(...)|postgres(...)|sqlite(...)|fromPdo(...)`. Consumers never create a PDO directly.
- **Connections are injected, never built internally.** Application takes `DbConnectionInterface`/`ConnectionPoolInterface`; Domain does not know them at all.
- **`fromPdo()` default `manageLifecycle: false`** — `disconnect()` is a no-op, the external system keeps control. Set to `true` only if the library is allowed to close the PDO.
- **External connections cannot be re-connected.** `reconnect()` on a disconnected external connection throws `RuntimeException`. In the pool: a dead external connection is skipped by failover — the external system must replace it.
- **ConnectionPool without replicas:** `new ConnectionPool(writer: $conn)` is allowed — the writer is then also used for reads. Load balancing via `ConnectionPoolConfig` (`STRATEGY_ROUND_ROBIN` default, `STRATEGY_RANDOM`).
- **Error semantics:** connection errors throw `RuntimeException`, config errors throw `InvalidArgumentException`.

## Full Reference

https://docs.jardis.io/en/adapter/dbconnection

<!-- source: jardisadapter/eventdispatcher -->
# jardisadapter/eventdispatcher

PSR-14 Event Dispatcher with priority ordering, type-hierarchy matching, stoppable events, and deferred dispatch via `EventCollector`. Synchronous, no external dependencies except `psr/event-dispatcher`.

## Usage essentials

- **Four classes, no subdirectories:** `EventDispatcher`, `ListenerProvider`, `Event`, `EventCollector`. No container, no Reflection, no ENV variables.
- **Register listeners explicitly** — no subscriber interface, no classpath scanning: `$provider->listen(OrderCreated::class, $listener, priority: 10)`. Higher priority = called first, default `0`.
- **Type-hierarchy matching:** Listeners on an interface/parent receive all implementing events (`listen(Event::class, $globalLogger)`).
- **Extending `Event` is optional.** Events must only implement `StoppableEventInterface` when `stopPropagation()` is needed — otherwise any class works.
- **Synchronous by design.** For cross-process / async → `jardisadapter/messaging`, not listener-based.
- **DDD Layer rule:** Domain defines event classes, Application dispatches, Infrastructure registers listeners. No dispatch calls in the Domain Layer.

## Full reference

https://docs.jardis.io/en/adapter/eventdispatcher

<!-- source: jardisadapter/filesystem -->
# jardisadapter/filesystem

Unified filesystem abstraction for local and S3-compatible backends (AWS, MinIO, DO Spaces). Orchestrator-with-Closures pattern, hardened against path traversal and symlink escape.

## Usage Essentials

- **Entry point via `FilesystemService`:** `$service->local($root)`, `$service->s3($bucket, $region, $key, $secret, endpoint?, prefix?)`. For power users: `$service->create(new LocalConfig(...) | new S3Config(...))` — only on the concrete service, not on the interface.
- **No singleton.** Multiple `Filesystem` instances per project are the norm (e.g. uploads local, backups on S3).
- **Contracts split into Reader/Writer** (`FilesystemReaderInterface` + `FilesystemWriterInterface`). For read-only consumers inject only the reader.
- **Visibility (`public`/`private`) not in the Contract** — only on the concrete `Filesystem` object. Reflection/feature check before calling if necessary.
- **Security is built in and cannot be bypassed:** Path traversal + null bytes rejected, symlink escape via `realpath()` containment, bucket-wipe guard on empty S3 prefix, `LIBXML_NONET` against XXE. `S3Config::$secret` is masked via `#[\SensitiveParameter]` + `__debugInfo()`.
- **Exception hierarchy:** Catch `FileNotFoundException`/`FileExistsException`/`UnableTo*Exception` specifically; base is `FilesystemExceptionInterface` from `jardissupport/contracts`.

## Full Reference

https://docs.jardis.io/en/adapter/filesystem

<!-- source: jardisadapter/http -->
# jardisadapter/http

PSR-18 HTTP client on cURL with handler pipeline (Transformers + Transport) and optional Retry. Contains its own PSR-7/PSR-17 implementation (`src/Message/Psr17Factory`) — no external PSR-7 dependencies.

## Usage essentials

- **User API:** `new HttpClient(requestFactory, streamFactory, responseFactory, uriFactory, config)`. `ClientConfig` is a readonly VO, `HttpClient` has zero business logic — only Pipeline orchestration.
- **Convenience methods set JSON automatically:** `post()`/`put()`/`patch()` serialize arrays to JSON and set `Content-Type` + `Accept`. `get()`/`delete()`/`head()` have no body. Optional last parameter is custom headers per request.
- **Pipeline is built from Config, only what is configured is instantiated:** Transformers (`BaseUrl`, `DefaultHeaders`, `BearerAuth`, `BasicAuth`) → Transport (`CurlTransport`, wrapped by `Retry` if `maxRetries > 0`). Bearer takes precedence over Basic.
- **No Exception on 4xx/5xx** — those are valid responses. Only `NetworkException` (DNS, Connect-Refused, Timeout) and `RequestException` (malformed URI) are thrown, both extend `HttpClientException`. The Retry wrapper retries 5xx + `HttpClientException` with exponential backoff (`retryDelayMs`).
- **Custom Transport injectable via Closure:** `transport: function (RequestInterface, ClientConfig): ResponseInterface`. Enables mocks without real HTTP calls — all Unit Tests in the package use this path.
- **No caching/logging in the package.** Implement cross-cutting concerns as a Decorator on `Psr\Http\Client\ClientInterface` in the caller project; never instantiate Handlers directly.

## Full reference

https://docs.jardis.io/en/adapter/http

<!-- source: jardisadapter/logger -->
# jardisadapter/logger

PSR-3 logging pipeline with 20+ handlers (Stream/Network/Queue/Storage/Browser/Smart), 7 formatters, and 6 enrichers. Fluent `LoggerBuilder` → immutable `Logger` via `getLogger()`.

## Usage essentials

- **Two-phase API:** `LoggerBuilder` configures (`addConsole()`, `addFile()`, `addRedis()`, `addFingersCrossed()`, …), `getLogger()` returns the immutable `Logger` object. No mutation after `getLogger()` — handler access is read-only only (`getHandler(name)`, `getHandlersByClass()`).
- **Connection Injection required:** `addRedis($redis, …)`, `addDatabase($pdo, …)`, `addRabbitMq($amqpConnection, …)`, `addKafkaMq($rdkafkaProducer, …)`. The package never creates its own connections; auth, database selection, and keepalive are the caller's responsibility.
- **Enrichers are plain callables** (`__invoke`): `logData()->addField('key', $enricher)` lands at root level (DB-column-capable), `->addExtra('key', $enricher)` in the `data` field (business context). Any Closure or callable works — no interface required.
- **Smart handlers as wrappers:** `LogFingersCrossed` buffers until activation level is reached (see DEBUG context on error), `LogSampling` reduces volume (Rate/Percentage/Smart/Fingerprint), `LogConditional` routes via callable condition. Ideal for high-traffic without log flooding.
- **Shared `HttpTransport`** serves `LogWebhook`, `LogSlack`, `LogTeams`, `LogLoki` — unified retry/timeout semantics; extensions `ext-redis`/`ext-amqp`/`ext-rdkafka` are in `suggest`, not `require`.
- **DDD Layer rule:** Application injects `LoggerInterface`, Infrastructure configures handlers via `LoggerBuilder`, Domain never imports logger classes. `Logger` swallows handler exceptions (optional `setErrorHandler(callable)`), so a broken handler does not block others.

## Full reference

https://docs.jardis.io/en/adapter/logger

<!-- source: jardisadapter/mailer -->
# jardisadapter/mailer

SMTP mailer on raw sockets with STARTTLS, AUTH LOGIN/PLAIN, MIME encoding, attachments, and connection keepalive for batch send. Only three user-facing classes: `Mailer`, `SmtpConfig`, `MailMessage`.

## Usage essentials

- **Entry point:** `new Mailer(new SmtpConfig(host: …, username: …, password: …, maxRetries: 3))`. `SmtpConfig` is a readonly VO; `fromAddress`/`fromName` are only applied when the message has no `From` set.
- **`MailMessage` is immutable with PSR-7-style `with*` pattern:** `MailMessage::create()->withFrom(…)->withTo(…)->withSubject(…)->withText(…)->withHtml(…)->withAttachment($bytes, $name)->withEmbeddedImage(…)`. `withTo()`/`withCc()`/`withBcc()` are additive. Interface getters (`from()`/`to()`/`attachments()`) return arrays; internal properties (`fromAddress`, `toAddresses`, …) are for handlers.
- **Pipeline:** Transformers (`DefaultFrom`, `MessageValidator`) → Encoder (`MimeEncoder` → `Envelope`) → Transport (`SmtpTransport`). Only configured handlers are instantiated; `Mailer` has zero business logic.
- **Retry is internal to Mailer** (not a wrapping handler): exponential backoff (`retryDelayMs`) on `SmtpConnectionException` and temporary 4xx errors; permanent 5xx errors are thrown immediately. `maxRetries: 0` = no retry.
- **Batch send shares one SMTP connection:** `sendBatch([$msg1, $msg2])` returns `BatchResult` with `successCount()`/`failureCount()`/`successful()`/`failed()`. `SmtpTransport` uses a NOOP health-check before connection reuse and silent reconnect on a dead connection.
- **Custom transport via `Closure(Envelope): void`** is injectable (testing / API send / log-to-file). All exceptions implement `JardisSupport\Contract\Mailer\MailerExceptionInterface` — generic catch is possible.

## Full reference

https://docs.jardis.io/en/adapter/mailer

<!-- source: jardissupport/classversion -->
# jardissupport/classversion

Versioned classes via Namespace-Injection and/or Proxy-Registry. Entry point: `$classVersion(Class::class, $version)` via `__invoke` — Composite of `LoadClassFromProxy` (wins) and a configurable class finder (`LoadClassFromSubDirectory` or `LoadClassFromExtensions`, with fallback chain), configured through `ClassVersionConfig`.

## Source layout

- `src/ClassVersion.php` — orchestrator (implements `ClassVersionInterface`).
- `src/Data/` — `ClassVersionConfig`.
- `src/Reader/` — resolvers that implement `ClassVersionInterface`: `LoadClassFromSubDirectory`, `LoadClassFromExtensions`, `LoadClassFromProxy`.
- `src/Support/` — helpers that do **not** implement `ClassVersionInterface` and never take ClassVersion's place: `ClassResolutionCache`, `TracingClassVersion`.

## Usage essentials

- **Loader order fixed:** `ClassVersion::__invoke` checks `LoadClassFromProxy` first (returns `object|null`), then falls back to the configured class finder (returns `class-string`). Return type is `mixed` — Proxy returns object, class finders return class name for `new $class()` instantiation.
- **Two class finders, pick one per `ClassVersion` instance:**
  - `LoadClassFromSubDirectory` — injects version **before the class name**: `Acme\Domain\User` + `v2` → `Acme\Domain\v2\User`.
  - `LoadClassFromExtensions(depth, segmentNames, ?config)` — inserts one or more segments at position `depth` from the left; versioned subdir goes after each segment. `segmentNames: array<string>` (default `['Extensions']`); `''` is a legal entry meaning "no subdir inserted, probe the root directly". With `depth:3, segmentNames:['Extensions']`, `Acme\BC\Agg\Command\Handler\Foo` → `Acme\BC\Agg\Extensions\v2\Command\Handler\Foo` → baseline `Acme\BC\Agg\Extensions\Command\Handler\Foo` → generator base. Multi-segment example `segmentNames: ['', 'Platform']` walks **versions-first across all segments** before falling back to baselines: `…\v2\…` → `…\Platform\v2\…` → `…\…` (dev baseline) → `…\Platform\…` (platform baseline) → generator base. Classes shorter than `depth+1` skip override lookup. Pure string math, zero array allocations on the happy path.
- **Fallback chain in `ClassVersionConfig`** explicitly as `['v3' => ['v2', 'v1']]` — no recursive resolution, the order is the lookup path. **The base class (without version) is the implicit final fallback and is NOT in the `fallbackChain()` array.** Alias resolution (`'current'` → `'v2'`) happens before chain lookup.
- **Label validation in constructor:** Keys/values must be non-empty strings, trimming + dedup applied, otherwise `InvalidArgumentException`. `version($label)` returns the key (or passthrough for unknown), `version(null)` → `''`. Labels are case-sensitive.
- **`LoadClassFromProxy` fluent:** `addProxy(Logger::class, new FileLogger(), 'prod')->addProxy(...)`, `removeProxy(Logger::class, 'prod')` cleans up empty buckets. Data structure: `$cachedProxy[$version][$className] = $object`. Without config, proxy only trims `$version`, no alias resolving.
- **`ClassResolutionCache` (optional helper):** passed as `new ClassVersion($config, $finder, $proxy, cache: new ClassResolutionCache())`. Memoizes hits **and** misses per `(className, version)` key. Exception is cached and re-thrown without re-running the inner resolver. API: `remember(string $key, callable $producer): mixed`, `clear(): void`. **Never replaces `ClassVersion`** — consumer type stays `ClassVersion`.
- **`TracingClassVersion` Decorator for debug:** `$tracing->getTrace()` returns a list of `['requested', 'version', 'resolved', 'type' => 'class-string'|'proxy']`. Exceptions propagate **without** a trace entry. Layer rule: **Application Layer yes — Domain Layer never imports `ClassVersion`.**

## Full reference

https://docs.jardis.io/en/support/classversion

<!-- source: jardissupport/dotenv -->
# jardissupport/dotenv

`.env` loader with two modes (Public + Private), two-stage `APP_ENV` bootstrap, cascade includes (`load()`/`load?()`), `${VAR}`/`~` substitution via `VariableRegistry`, `_FILE` secret resolution, and cast chain (Value → UserHome → Numeric → Bool → JSON → Array).

## Usage essentials

- **`loadPublic($path)` vs. `loadPrivate($path)`:** Public writes `putenv()` + `$_ENV` + `$_SERVER` (bootstrap, once per request) and returns `void`. Private returns `array<string,mixed>` without globals — this is the default for domain configs (`Infrastructure/Config/*Config` classes). Inject values as primitives into the domain, never inject the `DotEnv` service itself.
- **Two-stage bootstrap is fixed:** Stage 1 loads `.env` + `.env.local`, then `APP_ENV` is resolved from `VariableRegistry`/`$_ENV`/`getenv()`, Stage 2 loads `.env.{APP_ENV}` + `.env.{APP_ENV}.local`. Later files override earlier ones — `*.local` always comes after the base/env counterpart.
- **Cast chain runs in strict order with early exit on non-string:** `CastStringToValue` → `CastUserHome` → `CastStringToNumeric` → `CastStringToBool` → `CastStringToJson` → `CastStringToArray`. Add custom handlers via `DotEnv::addHandler($invokable, prepend: true)` before substitution; never call `CastTypeHandler` directly. Note: `ENABLED=1` becomes `int(1)` (Numeric takes precedence over Bool) — write `true`/`false` explicitly for booleans.
- **`VariableRegistry` is the single source of truth** for `${VAR}` and `~` expansion in both modes; `LoadValuesFromFiles` populates it before every cast. Never use `getenv()` directly for values from `.env` in code — otherwise Private mode isolation does not apply.
- **Include system:** `load(path.env)` is required (throws `EnvFileNotFoundException`), `load?(path.env)` is optional (silent skip); relative paths are resolved from the directory of the including file; each include runs the full cascade (base → .local → .{APP_ENV} → .{APP_ENV}.local). Circular includes are detected via a `realpath()` stack and throw `CircularEnvIncludeException::getIncludeStack()`.
- **`_FILE` pattern + optional `jardissupport/secret`:** Keys with the `_FILE` suffix (`DB_PASSWORD_FILE=/run/secrets/db_pw`) are read by the loader, trimmed, passed through the cast chain, and stored under the key without the suffix (`DB_PASSWORD`). Combinable with `jardissupport/secret`: if the file contains `secret(aes:...)` it is decrypted in the same pass. Layer rule: `DotEnv` lives in `Infrastructure`, **never** in the domain.

## Full reference

https://docs.jardis.io/en/support/dotenv

<!-- source: jardissupport/factory -->
# jardissupport/factory

Minimal PSR-11 container: a single `Factory` class, no shared registry, no ClassVersion support, Reflection fallback only for parameterless constructors.

## Usage essentials

- **One class, two APIs:** `Factory` implements `Psr\Container\ContainerInterface` (`get()`, `has()`) and additionally provides `create(string $className, mixed ...$parameters): object`. `get()` is a lookup with a fallback chain, `create()` always returns a new instance with parameters — no cache, no container lookup.
- **`get()` resolution order is strict:** 1) pre-registered `$instances` (exact key match), 2) backend `ContainerInterface::has()/get()`, 3) `class_exists()` + Reflection `new $className()`, 4) `NotFoundException`. Step 3 applies **only** for parameterless constructors — classes with required params via `get()` throw `ContainerException`; use `create()` for those.
- **Immutable after construction:** `$instances` and `$container` are `readonly`. No `register*()`/`registerShared()` methods, no post-construction mutation. All instances must be passed in the constructor: `new Factory($backend, ['logger' => $logger])`.
- **No shared registry, no instance reuse:** Step 3 (Reflection) creates a new instance every time — if Singleton behavior is required, inject a backend container (e.g. PHP-DI) or pre-register the instance.
- **No ClassVersion support.** Versioned classes are resolved in the Kernel (`jardiscore/kernel`), not in the Factory. The Factory sees only the final class name.
- **Layer rule:** `Factory` lives in `Infrastructure/Support` and is consumed by the Application Layer — the **Domain never imports** `JardisSupport\Factory\Factory`. Exceptions: `NotFoundException` (`extends \InvalidArgumentException implements NotFoundExceptionInterface`) and `ContainerException` (`extends \RuntimeException implements ContainerExceptionInterface`).

## Full reference

https://docs.jardis.io/en/support/factory

<!-- END jardis/dev-skills -->
