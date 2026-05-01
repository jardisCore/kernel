# jardiscore/kernel

DDD core: four-stage flow `DomainApp` (lazy bootstrap, ENV, service hooks) -> `DomainKernel` (immutable infrastructure via Constructor Injection) -> `BoundedContext` (use-case handler with Factory + ClassVersion) -> `ContextResponse` -> `DomainResponseTransformer` -> readonly `DomainResponse`.

## Usage essentials

- **`DomainApp::kernel()` is `final protected`** and is lazy-bootstrapped — never override. Customization runs exclusively through protected hooks: `cache()`, `logger()`, `eventDispatcher()`, `httpClient()`, `dbConnection()`, `mailer()`, `filesystem()`, `container()`, `classVersionConfig()`, `classVersion()`, `version()`, `domainRoot()`. Resolver API: `handle(class, ...$params)` delegates to a lazy internal `BoundedContext` using `version = $this->version()`, so Domain-level code can use the same `$this->handle(X::class)` idiom as BC-level code. `new MyDomain()` without parameters is the usage path.
- **Three-state service resolution in `DomainApp::resolve()`:** object → use locally + register in `ServiceRegistry` (first-write-wins, `null` is filtered internally); `null` → shared fallback from the registry; `false` → explicitly disabled, no fallback.
- **`DomainKernel` is a pure consumer** — immutable, builds nothing itself, loads no ENV. All services come via constructor; `env(string $key)` is case-insensitive (private ENV > `$_ENV`, stored lowercase internally via `array_change_key_case`). `container()` always returns **`Factory`** (not just `ContainerInterface`) — Factory wraps an external container and provides `create()` in addition to PSR-11 `get()`/`has()`.
- **`ServiceRegistry` is statically shared** across all `DomainApp` instances — the only static state in the package, a deliberate trade-off for zero-config multi-domain support (e.g. `new MeterDevice()` without constructor args).
- **`BoundedContext` exposes two entry points** — both go through the same private `resolve()` helper (single Try/Catch + Logger + rethrow):
  - `handle($class, ...$params)` — pass-through, inherits caller's `payload+version`. Used in the entire downstream call chain.
  - `context($class, $payload, $version = '')` — fresh context, sets `payload+version` explicitly. Used at API boundaries (Command/Query/Service registry methods) to begin a new call chain. Throws `LogicException` for non-`BoundedContextInterface` targets — `payload+version` are meaningless there.
- **`resolve()` stages:** ClassVersion-resolve against the chosen `$version` → return proxy/object short-circuit → `BoundedContextInterface` subclass via `Factory::create($class, $kernel, $payload, $version, ...$params)` → `LogicException` if `context()` was called for non-BC → Factory with extra params → `Container::get()`. ClassVersion is discovered automatically from the container.
- **Response Pipeline:** `ContextResponse` mutable/fluent (`addData`/`addEvent`/`addError`/`addResult` nestable), `DomainResponseTransformer` aggregates recursively to readonly `DomainResponse`. Status: 0 errors → `ResponseStatus::Success` (200), errors present → `ValidationError` (400), override via second `transform()` argument. `dbConnection()` accepts `ConnectionPoolInterface|PDO|null` — plain PDO is the default, pool is optional only.

## Full reference

https://docs.jardis.io/en/core/kernel
