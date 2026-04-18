---
name: core-kernel
description: jardiscore/kernel - DomainApp, DomainKernel, BoundedContext, ServiceRegistry, ContextResponse, DomainResponse, ResourceSharing. TRIGGER: DomainApp, DomainKernel, BoundedContext, kernel(), resource(), result(), payload(), ContextResponse, DomainResponse, ServiceRegistry, SharedRegistry.
user-invocable: false
---

# KERNEL_COMPONENT_SKILL
> `jardiscore/kernel` | NS: `JardisCore\Kernel` | PHP 8.2+

## ARCHITECTURE
```
DomainApp (lazy bootstrap, ClassVersion, ENV, service sharing)
    ↓ kernel()
DomainKernel (immutable, constructor injection)
    ↓ passed to
BoundedContext (use-case handler, Factory + ClassVersion resolution)
    ↓ result()
ContextResponse (mutable accumulator) → DomainResponseTransformer → DomainResponse (readonly)
```

## CLASSES
| Class | Responsibility |
|-------|---------------|
| `DomainApp` | Entry point. Lazy kernel bootstrap. Service hooks. Shared `ServiceRegistry` |
| `DomainKernel` | Immutable. Constructor injection. ENV case-insensitive. `container()` → `Factory` |
| `BoundedContext` | Class resolution via Factory + ClassVersion. Payload/Version. Result accumulator |
| `ServiceRegistry` | PSR-11 + `set()`. First-write-wins. Statically shared across all `DomainApp` instances |
| `ContextResponse` | Mutable, fluent. Context-keyed Data/Events/Errors. Nestable |
| `DomainResponse` | Readonly. Aggregated Data/Events/Errors/Metadata |
| `DomainResponseTransformer` | ContextResponse tree → DomainResponse (recursive) |
| `ResponseStatus` | IntBacked Enum: 200, 201, 204, 400, 401, 403, 404, 409, 500 |

## DOMAINAPP

```php
class MyDomain extends DomainApp
{
    public function order(): OrderContext
    {
        return new OrderContext($this->kernel());
    }
}
// Usage: new MyDomain()  — no parameter needed
```

### Protected hooks (overridable)
| Method | Return | Note |
|--------|--------|------|
| `domainRoot()` | `string` | Auto-detection via Reflection. Cached |
| `container()` | `?ContainerInterface` | External DI (Symfony, PHP-DI) |
| `classVersionConfig()` | `ClassVersionConfig` | Labels + fallback chains |
| `classVersion()` | `ClassVersionInterface` | Default: SubDirectory + Proxy |
| `cache()` | `CacheInterface\|false\|null` | PSR-16 |
| `logger()` | `LoggerInterface\|false\|null` | PSR-3 |
| `eventDispatcher()` | `EventDispatcherInterface\|false\|null` | PSR-14 |
| `httpClient()` | `ClientInterface\|false\|null` | PSR-18 |
| `dbConnection()` | `ConnectionPoolInterface\|PDO\|false\|null` | |
| `mailer()` | `MailerInterface\|false\|null` | |
| `filesystem()` | `FilesystemServiceInterface\|false\|null` | |

### Three-state service resolution
| Return | Meaning |
|--------|---------|
| object | Use locally + register in shared registry (first-write-wins) |
| `null` | No local service → shared registry fallback |
| `false` | Explicitly disabled, no fallback |

### Not overridable
- `kernel()` — `final protected`, bootstrap logic fixed
- `factory()`, `resolve()`, `loadEnv()` — `private`

## DOMAINKERNEL

```php
$kernel = new DomainKernel(
    domainRoot: '/path/to/domain',      // required
    container: $factory,                // ?ContainerInterface
    cache: $cache,                      // ?CacheInterface
    logger: $logger,                    // ?LoggerInterface
    eventDispatcher: $dispatcher,       // ?EventDispatcherInterface
    httpClient: $client,                // ?ClientInterface
    connection: $pool,                  // ConnectionPoolInterface|PDO|null
    mailer: $mailer,                    // ?MailerInterface
    filesystem: $filesystemService,     // ?FilesystemServiceInterface
    env: ['db_host' => 'localhost'],    // array — private ENV, takes precedence over $_ENV
);
```

| Method | Return |
|--------|--------|
| `domainRoot()` | `string` |
| `env(string $key)` | `mixed` — case-insensitive; private ENV > `$_ENV` |
| `container()` | `Factory` — always wraps injected container |
| `cache()` | `?CacheInterface` |
| `logger()` | `?LoggerInterface` |
| `eventDispatcher()` | `?EventDispatcherInterface` |
| `httpClient()` | `?ClientInterface` |
| `dbConnection()` | `ConnectionPoolInterface\|PDO\|null` |
| `mailer()` | `?MailerInterface` |
| `filesystem()` | `?FilesystemServiceInterface` |

ENV keys stored lowercase internally (`array_change_key_case`).

## SERVICEREGISTRY

```php
$registry->set(CacheInterface::class, $cache);   // first-write-wins; subsequent set() ignored
$registry->has(CacheInterface::class);            // bool
$registry->get(CacheInterface::class);            // mixed
```

- Static shared via `DomainApp::$sharedRegistry` — only static state in the package
- `null` is never passed to `set()` (filtered in `DomainApp::resolve()`)

## BOUNDEDCONTEXT

```php
class PlaceOrder extends BoundedContext
{
    public function __invoke(): DomainResponse
    {
        $order = $this->payload();
        $pdo   = $this->resource()->dbConnection();
        $this->result()->addData('orderId', 42);
        $this->result()->addEvent('OrderPlaced', $order);
        return (new DomainResponseTransformer())->transform($this->result());
    }
}
```

### Protected API
| Method | Return | Description |
|--------|--------|-------------|
| `resource()` | `DomainKernelInterface` | Kernel access |
| `payload()` | `mixed` | Request data |
| `version()` | `string` | ClassVersion string |
| `result()` | `ContextResponseInterface` | Lazy ContextResponse (cached) |
| `handle(className, ...$params)` | `mixed` | Class resolution + instantiation |

### handle() resolution order
1. ClassVersion in container? → `$classVersion($className, $version)`
2. Result is object? → Proxy, return directly
3. BoundedContext subclass? → `Factory::create($class, $kernel, $payload, $version, ...$params)`
4. Parameters present? → `Factory::create($class, ...$params)`
5. Otherwise → `Container::get($class)`
6. Exception → Logger + rethrow

## RESPONSE PIPELINE

```php
// ContextResponse — mutable, fluent
$result = new ContextResponse('OrderContext');
$result->addData('orderId', 42)
       ->addEvent(new OrderPlaced($order))
       ->addError('Validation failed');
$result->addResult($subContextResult);   // nest sub-context

// DomainResponseTransformer
$response = (new DomainResponseTransformer('v1.0'))->transform($result);
// Explicit status override:
$response = (new DomainResponseTransformer())->transform($result, ResponseStatus::NotFound);

// DomainResponse — readonly
$response->isSuccess();   // true for 200, 201, 204
$response->getStatus();   // int (ResponseStatus value)
$response->getData();     // array<string, array<string, mixed>>  (context-keyed)
$response->getEvents();   // array<string, array<int, object>>
$response->getErrors();   // array<string, array<int, string>>
$response->getMetadata(); // duration, contexts, timestamp, version
```

**Status resolution:** no errors → 200; errors present → 400; explicit second param overrides.

## EXTENDING THE KERNEL

The 8 built-in hooks cover PSR services (Cache, Logger, Events, HTTP, DB, Mailer, Filesystem). For custom resources (MQTT client, TenantContext, domain-specific session) two paths exist.

| Path | Access in BoundedContext | Typical use |
|------|-------------------------|-------------|
| **A — Container** | `$this->handle(MqttClient::class)` | Default choice — resource is a Service |
| **B — App getter** | not reachable via `resource()` — inject as payload | Resource is app-scoped, not per-use-case |

### Path A — Container-Injection (recommended)

Override `container()` to return a PSR-11 container pre-filled with custom services. `handle()` inside the BoundedContext resolves through it (`handle()` order step 5: `Container::get($class)`).

```php
class MyApp extends JardisApp
{
    protected function container(): ?ContainerInterface
    {
        $c = new Factory();   // or Symfony\DI, PHP-DI — any PSR-11
        $c->set(MqttClient::class, new MqttClient($this->env('mqtt_host')));
        $c->set(TenantContext::class, new TenantContext($this->env('tenant')));
        return $c;
    }
}
// In BoundedContext:
$mqtt = $this->handle(MqttClient::class);
```

ClassVersion still applies — v2 overrides work if the registered class is version-resolvable.

### Path B — App-level getter

For resources consumed outside BoundedContexts (CLI bootstrap, framework middleware, health checks).

```php
class MyApp extends JardisApp
{
    private ?MqttClient $mqtt = null;
    public function mqtt(): MqttClient
    {
        return $this->mqtt ??= new MqttClient($this->env('mqtt_host'));
    }
}
// Usage: $app = new MyApp(); $app->mqtt()->publish(...);
```

The kernel does not know about `mqtt()`. If a BoundedContext needs it, pass it in the payload or prefer Path A.

### Not a path — direct ServiceRegistry write

`DomainApp::$sharedRegistry` is `private static`. It is populated only through the 8 hooks via `resolve()`. Do not attempt to reach into it from outside.

## RULES
- `kernel()` is `final` — never override
- `DomainKernel` is purely immutable — builds nothing, only consumes
- ClassVersion is auto-discovered from the container inside `BoundedContext`
- `ServiceRegistry`: first-write-wins, `null` never registered
- Prefer plain PDO; `ConnectionPool` only when read replicas or health-check needed

## DEPENDENCIES
```
jardissupport/contract      ^1.0
jardissupport/classversion  ^1.0
jardissupport/dotenv        ^1.0
jardissupport/factory       ^1.0
psr/container               ^2.0
psr/log                     ^3.0
psr/simple-cache            ^3.0
psr/event-dispatcher        ^1.0
psr/http-client             ^1.0
# suggest: jardisadapter/dbconnection (ConnectionPool — NOT required; plain PDO works)
```
