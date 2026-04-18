# Jardis Kernel

![Build Status](https://github.com/jardisCore/kernel/actions/workflows/ci.yml/badge.svg)
[![Latest Version](https://img.shields.io/packagist/v/jardiscore/kernel.svg)](https://packagist.org/packages/jardiscore/kernel)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)
[![Coverage](https://img.shields.io/badge/Coverage-91%25-brightgreen.svg)](https://github.com/jardisCore/kernel)
[![PSR-3](https://img.shields.io/badge/PSR--3-Logger-blue.svg)](https://www.php-fig.org/psr/psr-3/)
[![PSR-11](https://img.shields.io/badge/PSR--11-Container-blue.svg)](https://www.php-fig.org/psr/psr-11/)
[![PSR-14](https://img.shields.io/badge/PSR--14-Event%20Dispatcher-blue.svg)](https://www.php-fig.org/psr/psr-14/)
[![PSR-16](https://img.shields.io/badge/PSR--16-Simple%20Cache-blue.svg)](https://www.php-fig.org/psr/psr-16/)
[![PSR-18](https://img.shields.io/badge/PSR--18-HTTP%20Client-blue.svg)](https://www.php-fig.org/psr/psr-18/)

> Part of the **[Jardis Business Platform](https://jardis.io)** — Enterprise-grade PHP components for Domain-Driven Design

The DDD kernel you always wanted. Eight files. Zero magic. Full control.

---

## Why Jardis Kernel?

Most DDD frameworks force you into their world. Jardis Kernel gives you the building blocks and stays out of the way.

- **`new DomainApp()` — done.** No configuration, no YAML, no service files. Your domain boots itself.
- **Plain PDO works.** Pass a PDO, get going. Need connection pooling later? Swap in a ConnectionPool. Same API.
- **Services shared across domains automatically.** Five domains, one database connection. First-write-wins. Zero plumbing.
- **Every service is optional.** Need a cache? Override one method. Don't need one? Don't touch anything.
- **ClassVersion built in.** Versioned classes via namespace injection — the foundation for the Jardis Builder.
- **Immutable kernel.** Once built, nothing changes. Safe for application servers, workers, long-running processes.

---

## Installation

```bash
composer require jardiscore/kernel
```

---

## Quickstart

### 1. Create your Domain

```php
use JardisCore\Kernel\DomainApp;

class Ecommerce extends DomainApp
{
    public function order(): OrderContext
    {
        return new OrderContext($this->kernel());
    }
}
```

### 2. Define a Bounded Context

```php
use JardisCore\Kernel\BoundedContext;
use JardisCore\Kernel\Response\DomainResponseTransformer;

class PlaceOrder extends BoundedContext
{
    public function __invoke(): DomainResponse
    {
        $order = $this->payload();
        $pdo = $this->resource()->dbConnection();

        $stmt = $pdo->prepare('INSERT INTO orders (customer, total) VALUES (?, ?)');
        $stmt->execute([$order['customer'], $order['total']]);

        $this->result()->addData('orderId', (int) $pdo->lastInsertId());
        $this->result()->addEvent(new OrderPlaced($order));

        return (new DomainResponseTransformer())->transform($this->result());
    }
}
```

### 3. Use it

```php
$shop = new Ecommerce();
$response = $shop->order()->placeOrder(['customer' => 'Acme', 'total' => 99.90]);

$response->isSuccess();   // true
$response->getData();     // ['PlaceOrder' => ['orderId' => 42]]
$response->getEvents();   // ['PlaceOrder' => [OrderPlaced {...}]]
```

That's it. No bootstrap file. No container setup. No framework.

---

## Provide a Database

The simplest way — a plain PDO:

```php
class Ecommerce extends DomainApp
{
    protected function dbConnection(): ConnectionPoolInterface|PDO|false|null
    {
        return new PDO('mysql:host=localhost;dbname=shop', 'root', '');
    }
}
```

That's all you need. A PDO. Works everywhere.

---

## Provide Services

Override protected methods to add infrastructure. Every method uses three-state logic:

| Return | Meaning |
|--------|---------|
| **object** | Use this service. Share it with other DomainApps (first-write-wins). |
| **null** | No local service. Use the shared one from another DomainApp if available. |
| **false** | Explicitly disabled. Don't use shared fallback either. |

```php
class Ecommerce extends DomainApp
{
    protected function dbConnection(): ConnectionPoolInterface|PDO|false|null
    {
        return new PDO('mysql:host=localhost;dbname=shop', 'root', '');
    }

    protected function logger(): LoggerInterface|false|null
    {
        return new MyLogger('/var/log/shop.log');
    }

    protected function classVersionConfig(): ClassVersionConfig
    {
        return new ClassVersionConfig(
            version: ['v1' => ['v1'], 'v2' => ['v2', 'current']],
            fallbacks: ['v2' => ['v1']],
        );
    }
}
```

---

## Multi-Domain Service Sharing

Multiple domains in one application share services automatically:

```php
$ecommerce = new Ecommerce();   // Builds PDO, registers it shared
$billing   = new Billing();     // Gets the same PDO — zero config
$analytics = new Analytics();   // Same. First-write-wins.
```

A domain that needs its own connection? Override the method. A domain that wants no connection at all? Return `false`.

---

## Advanced: ConnectionPool (optional)

For application servers and read replicas, install `jardisadapter/dbconnection` and use ConnectionPool instead of plain PDO:

```bash
composer require jardisadapter/dbconnection
```

```php
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;

class Ecommerce extends DomainApp
{
    protected function dbConnection(): ConnectionPoolInterface|PDO|false|null
    {
        $factory = new ConnectionFactory();

        return new ConnectionPool(
            writer: $factory->mysql('primary', 'user', 'pass', 'shop'),
            readers: [
                $factory->mysql('replica1', 'user', 'pass', 'shop'),
                $factory->mysql('replica2', 'user', 'pass', 'shop'),
            ],
        );
    }
}
```

ConnectionPool provides lifecycle management, health checks, round-robin load balancing, and automatic writer fallback when no readers are available. The rest of your code doesn't change.

---

## Direct Kernel Usage

For full control without DomainApp, use DomainKernel directly:

```php
use JardisCore\Kernel\DomainKernel;

$kernel = new DomainKernel(
    domainRoot: __DIR__ . '/src',
    connection: new PDO('mysql:host=localhost;dbname=shop', 'root', ''),
    logger: $myLogger,
    env: ['app_env' => 'production'],
);

$kernel->env('APP_ENV');     // 'production' (case-insensitive)
$kernel->dbConnection();     // PDO instance
$kernel->container();        // Factory (always available)
```

---

## Architecture

```
DomainApp                       Entry point. Lazy bootstrap. Service sharing.
    ├── domainRoot()            Auto-detected via Reflection
    ├── classVersion()          Built from classVersionConfig()
    ├── cache()                 Three-state: object | null | false
    ├── logger()                   ↓
    ├── eventDispatcher()          ↓
    ├── httpClient()               ↓
    ├── dbConnection()             ↓
    ├── mailer()                   ↓
    ├── filesystem()               ↓
    ├── factory()               Factory + ClassVersion + DI Container
    └── loadEnv()               domainRoot/.env → private ENV

DomainKernel                    Immutable. Constructor injection only.
    ├── env(key)                Case-insensitive. Private > $_ENV
    ├── container()             Always Factory. Wraps external container.
    ├── cache()                 ?CacheInterface
    ├── logger()                ?LoggerInterface
    ├── eventDispatcher()       ?EventDispatcherInterface
    ├── httpClient()            ?ClientInterface
    ├── dbConnection()          ConnectionPoolInterface | PDO | null
    ├── mailer()                ?MailerInterface
    └── filesystem()            ?FilesystemServiceInterface

BoundedContext                  Use case handler.
    ├── handle(class, ...args)  Smart resolution: ClassVersion → Factory → Container
    ├── resource()              Access to DomainKernel
    ├── payload()               Request data
    └── result()                Lazy ContextResponse

ContextResponse → DomainResponseTransformer → DomainResponse
    Mutable accumulator    Recursive aggregation    Immutable answer
```

---

## Related Packages

**Included dependencies:**

| Package | Purpose |
|---------|---------|
| `jardissupport/contract` | Interface contracts (DomainKernelInterface, etc.) |
| `jardissupport/classversion` | Versioned class resolution via namespace injection |
| `jardissupport/factory` | PSR-11 Container + class instantiation |
| `jardissupport/dotenv` | ENV file loading |

**Optional (composer suggest):**

| Package | Purpose |
|---------|---------|
| `jardisadapter/dbconnection` | ConnectionPool with read/write splitting, health checks, load balancing |

---

## Kernel and Foundation

Jardis Kernel is a **platform** — it provides the building blocks but leaves service assembly to you. Its sister package **[Jardis Foundation](https://github.com/jardisCore/foundation)** (`jardiscore/foundation`) builds on top of Kernel and turns it into a ready-to-run solution:

| | Kernel | Foundation |
|---|--------|-----------|
| **Approach** | Override protected methods, wire services yourself | Everything configured via `.env` |
| **Entry point** | `class MyApp extends DomainApp` | `class MyApp extends JardisApp` |
| **Services** | You build them | Auto-assembled from ENV variables |
| **Dependencies** | Minimal (4 packages + PSR interfaces) | Full Jardis ecosystem (Cache, Logger, DbConnection, EventDispatcher, HTTP) |
| **Use case** | Custom setups, libraries, testing | Production DDD projects |

**When to use which:** Start with Foundation for most projects — it handles all infrastructure wiring. Use Kernel directly when you need full control or want to integrate Jardis into an existing DI setup.

### Platform for the Jardis Builder

Both Kernel and Foundation serve as the **runtime platform** for code generated by the **Jardis Builder**. The Builder generates DDD project structures — Aggregates, BoundedContexts, Repositories, Commands, Queries — that run directly on these platforms:

```
Jardis Builder (development-time)
    │
    │  generates
    ▼
DDD Project Code (Aggregates, BoundedContexts, Repositories, ...)
    │
    │  runs on
    ▼
Foundation (JardisApp)  ──extends──▶  Kernel (DomainApp)
    ENV-driven                        Manual wiring
    Production-ready                  Full control
```

ClassVersion — built into the Kernel — is the foundation for the Builder's versioned class resolution. It enables namespace-based class versioning so generated code can evolve without breaking existing consumers.

---

## Documentation

Full documentation, guides, and API reference:

**[docs.jardis.io/en/core/kernel](https://docs.jardis.io/en/core/kernel)**

---

## License

Jardis is open source under the [MIT License](LICENSE.md).
Free for any purpose — commercial or non-commercial.

---

*Jardis — Development with Passion*
*Built by [Headgent Development](https://headgent.com)*

<!-- BEGIN jardis/dev-skills README block — do not edit by hand -->
## KI-gestützte Entwicklung

Dieses Package liefert einen Skill für Claude Code, Cursor, Continue und Aider mit. Installation im Konsumentenprojekt:

```bash
composer require --dev jardis/dev-skills
```

Mehr Details: <https://docs.jardis.io/skills>
<!-- END jardis/dev-skills README block -->
