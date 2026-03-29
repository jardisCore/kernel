# Jardis Domain Core

**DDD Domain Core for PHP — BoundedContext, ContextResult, DomainResponse, DomainKernel.**

Part of the [Jardis Ecosystem](https://jardis.io) for Domain-Driven Design.

---

## Overview

The DDD core with 7 files. Pull this package and start building domain logic immediately.

| Class | Purpose |
|-------|---------|
| `DomainKernel` | Simple kernel — constructor injection, immutable |
| `BoundedContext` | BC handler with PSR-11 Container + ClassVersion discovery |
| `ContextResult` | Mutable transport accumulator for BC chains |
| `DomainResponse` | Immutable final response from domain operations |
| `DomainResponseTransformer` | ContextResult tree → DomainResponse aggregation |
| `ResponseStatus` | Domain-neutral status enum (200, 400, 404, 500, ...) |

---

## Installation

```bash
composer require jardiscore/domain
```

---

## Quickstart

```php
use JardisCore\Domain\DomainKernel;
use JardisCore\Domain\BoundedContext;

$kernel = new DomainKernel(
    appRoot: __DIR__,
    domainRoot: __DIR__ . '/src',
    logger: $yourLogger,
    dbWriter: new PDO('mysql:host=localhost;dbname=shop', 'root', 'secret'),
);

// Your BoundedContext subclass uses the kernel
$context = new PlaceOrderContext($kernel, $orderData);
$result = $context->execute();
```

---

## Architecture

```
DomainKernel (infrastructure)
    ↓
BoundedContext (use case handler)
    ↓
ContextResult (mutable accumulator)
    ↓
DomainResponseTransformer
    ↓
DomainResponse (immutable answer)
```

---

## Related Packages

| Package | Role |
|---------|------|
| `jardisport/domain` | Interface contracts (DomainKernelInterface, etc.) |
| `jardiscore/foundation` | Jardis platform with ENV-based zero-config bootstrap |

---

## License

Jardis is source-available under the [PolyForm Shield License 1.0.0](LICENSE.md).
Free for virtually every purpose — including commercial use.

---

*Jardis – Development with Passion*
*Built by [Headgent Development](https://headgent.com)*
