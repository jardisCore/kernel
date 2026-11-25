## CLASSES

| Class | Responsibility | Status |
|-------|---------------|--------|
| `DomainKernel` | Immutable, constructor injection, implements `DomainKernelInterface` (15 methods). `container()` → `Factory` | **current** |
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
| `ContextResponse` / `DomainResponse` / `DomainResponseTransformer` / `ResponseStatus` | Response pipeline | **deleted from this package** — responses come from `jardissupport/response` (4.2); `ResponseStatus` and the response interfaces live in `jardissupport/contracts` |
## THE GENERATED SIDE (not in this package)

Everything downstream of `DomainKernel` is generated per domain by the Jardis
Builder — see `generated-code-extend` for the full contract:

- **`{Domain}Context`** — the generated, hermetic base every BC/Aggregate
  facade in the domain extends. Carries the Kernel-Naht `handle()`/`context()`
  (now `protected` — family-internal only), `resource()`/`payload()`/
  `version()`/`result()`, and `classVersion()`/`classVersionConfig()`.
  `implements JardisSupport\Contract\Kernel\GeneratedContextInterface` (the D5
  marker) — **no `extends BoundedContext`**, no package base class at all.
- **Responses** — no generated Response classes: `{Domain}Context::result()`
  returns a `ContextResponseInterface` (implemented by `ContextResponse` from
  `jardissupport/response`) and `transformer()` a `TransformResponse`; process
  doors answer through `ProjectOutcome` (`@outcome`). `ResponseStatus` lives in
  `jardissupport/contracts`.
- **The Domain facade** (e.g. `Ecommerce`) is `final class`, holds only the
  `DomainKernel` (`DomainKernelInterface $kernel`), and self-registers every
  aggregate's event router via `$kernel->eventListenerRegistry()`.

If you are extending or wiring **generated** code, read `generated-code-extend`
/ `generated-code-wire-transport`, not this skill — this package's surface stops at the
`DomainKernel`.

HTTP-Delivery für den DomainKernel (FastRoute-Router, PSR-15-Pipeline, kanonischer
`DomainResponseInterface`→PSR-7-Mapper): siehe `core-app` (`jardiscore/app`).
## DEPENDENCIES
```
jardissupport/contracts     ^2.19
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
