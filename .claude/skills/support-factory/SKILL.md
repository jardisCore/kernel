---
name: support-factory
description: PSR-11 Container with Reflection-based instantiation and backend container delegation.
user-invocable: false
zone: post-active
persona: C
prerequisites: [rules-architecture, rules-patterns]
next: []
---

# FACTORY_COMPONENT_SKILL
> `jardissupport/factory` | NS: `JardisSupport\Factory` | Implements `Psr\Container\ContainerInterface` | PHP 8.2+

## RESOLUTION FLOW
```
Factory::get($id)
  1. Pre-registered instances (exact key match) → return
  2. Backend container?.has($id)              → container.get($id)
  3. class_exists($id)                        → Reflection (parameterless constructors only)
  4. throw NotFoundException

Factory::create($className, ...$params)
  → Always new instance via Reflection with parameters; no cache, no container lookup
```

## API
```php
new Factory(?ContainerInterface $container = null, array $instances = [])

$factory->get(string $id): mixed           // PSR-11, fallback chain
$factory->has(string $id): bool            // PSR-11
$factory->create(string $className, mixed ...$parameters): object  // always new, accepts params
```

## RESOLUTION ORDER

| Step | Source | Parameters | Caching |
|------|--------|------------|---------|
| 1 | Pre-registered `$instances` | ignored | immutable after construction |
| 2 | Backend `ContainerInterface` | ignored (container-managed) | container-dependent |
| 3 | Reflection `new $className()` | only parameterless | no instance reuse |

## `create()` vs `get()`

| Aspect | `get($id)` | `create($class, ...$params)` |
|--------|------------|------------------------------|
| Checks instances | Yes | No |
| Checks container | Yes | No |
| Accepts parameters | No | Yes (variadic) |
| New instance | Only on Reflection fallback | Always |
| Missing constructor param | `ContainerException` | passed via `newInstanceArgs()` |

## EXCEPTIONS

| Exception | Trigger |
|-----------|---------|
| `NotFoundException` (implements `NotFoundExceptionInterface`) | `get()` — class not resolvable |
| `ContainerException` (implements `ContainerExceptionInterface`) | `get()` — class has required constructor params; `create()` — class does not exist |

## IMPORTANT
- **No ClassVersion support** — ClassVersion resolution happens in the Kernel.
- **No shared registry** — no `registerShared()`, instances not cached.
- **Immutable** — `$instances` are `readonly`; no registration after construction.
- Classes without a constructor: `create()` with parameters silently ignores them.

## LAYER
- Application layer: use `Factory`
- Domain layer: NEVER imports `Factory`

## DEPENDENCIES
- `psr/container ^2.0`
