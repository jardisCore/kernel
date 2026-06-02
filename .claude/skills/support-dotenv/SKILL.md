---
name: support-dotenv
description: Load .env files with type casting, variable substitution, cascade loading, secret support. Use for DotEnv or env config.
user-invocable: false
zone: post-active
persona: C
prerequisites: [rules-architecture, rules-patterns]
next: [support-secret]
---

# DOTENV_COMPONENT_SKILL
> jardissupport/dotenv v1.0.0 | NS: `JardisSupport\DotEnv` | Implements: `DotEnvInterface` | PHP 8.2+

## ARCHITECTURE
```
DotEnv                    facade, two-stage APP_ENV loading
  LoadFilesFromPath        file discovery (getBaseFiles / getEnvFiles / __invoke)
  LoadValuesFromFiles      parser, include cascade, _FILE resolution, VariableRegistry population
    ParseLoadDirective     load() / load?() parser
    CastTypeHandler        type-cast chain orchestrator (internal, holds VariableRegistry)
      VariableRegistry     central raw-value store for ${VAR} and ~ resolution
```

**Constructor:**
```php
new DotEnv(?LoadFilesFromPath $fileFinder = null, ?LoadValuesFromFiles $fileContentReader = null)
```
`CastTypeHandler` is created internally — not in constructor.

## TWO-STAGE LOADING
1. Load `.env` + `.env.local`
2. Resolve `APP_ENV` (parsed result → `$_ENV` → `getenv()`)
3. Load `.env.{APP_ENV}` + `.env.{APP_ENV}.local`

## API
```php
$dotEnv = new DotEnv();
$dotEnv->loadPublic(string $path): void           // putenv + $_ENV + $_SERVER
$dotEnv->loadPrivate(string $path): array<string,mixed>  // no globals, returns cast values

$dotEnv->addHandler(object $handler, bool $prepend = false): void   // invokable, else InvalidArgumentException
$dotEnv->removeHandler(string $handlerClass): void
```
`addHandler()` → `CastTypeHandler::setCastTypeInstance()`.  
`removeHandler()` → `CastTypeHandler::removeCastTypeClass()`.

## PUBLISH BEHAVIOR
| Mode | `putenv` | `$_ENV` / `$_SERVER` | Return |
|------|----------|----------------------|--------|
| `loadPublic()` | raw string | cast value | `void` |
| `loadPrivate()` | — | — | `array<string,mixed>` (cast) |
Both modes: VariableRegistry populated identically → `${VAR}` and `~` work in both.

## TYPE CAST CHAIN (strict order, early exit on non-string)
1. `CastStringToValue` — `${VAR}` via VariableRegistry (+ `getenv()` fallback) → `?string`
2. `CastUserHome` — `~` via VariableRegistry HOME (+ `getenv()` fallback) → `?string`
3. `CastStringToNumeric` — `is_numeric()` → `int|float|string|null`
4. `CastStringToBool` — `true/false/1/0` via `filter_var()` → `bool|string|null`
5. `CastStringToJson` — `{...}` / `[...]` via `json_decode`, recursive → `array|string|null`
6. `CastStringToArray` — `[key=>val,1,2]` custom syntax, recursive → `array|string|null`

**Key edge cases:** `ENABLED=1` → `int(1)` via Numeric (NOT bool). `DEBUG=true` → `bool(true)`. `ZERO=0` → `int(0)`.

**Instance creation by `CastTypeHandler`:**
- `CastStringToValue`, `CastUserHome` → `new $class($registry)`
- `CastStringToNumeric`, `CastStringToBool` → `new $class()`
- All others (incl. custom) → `new $class($castTypeHandler)`

## VARIABLE REGISTRY
```php
$registry = $castTypeHandler->getRegistry();
$registry->set('KEY', 'raw_value');   // raw string before casting
$registry->get('KEY');                // Registry first, getenv() fallback
$registry->reset();                   // clear all entries
```
Populated in `LoadValuesFromFiles` for every variable (before casting). Used by cascade `buildCascadeFiles` for APP_ENV.

## INCLUDE SYSTEM
```env
load(.env.database)           # required — throws EnvFileNotFoundException if missing
load("path/with spaces/.env") # quoted paths supported
load?(.env.local)             # optional — silent skip
```
- Relative paths resolved from directory of the including file
- Each include cascades: base → `.local` → `.{APP_ENV}` → `.{APP_ENV}.local`
- APP_ENV read from VariableRegistry (works in both modes)
- Circular reference detection via `realpath()` stack → `CircularEnvIncludeException`

## FILE SECRET RESOLUTION (`_FILE` PATTERN)
```env
DB_PASSWORD_FILE=/run/secrets/db_password   # → DB_PASSWORD = trimmed file content
API_PORT_FILE=secrets/port                  # relative paths resolved from .env directory
```
- `KEY_FILE` suffix stripped → becomes `KEY`
- File content trimmed, passed through full cast chain
- Raw value registered in VariableRegistry (for `${VAR}` refs)
- Missing file → `EnvFileNotFoundException`; unreadable → `EnvFileNotReadableException`
- Combinable with `jardissupport/secret`: file contains `secret(aes:...)` → decrypted via cast chain

## OPTIONAL SECRET SUPPORT
```php
// composer require jardissupport/secret
$dotEnv = new DotEnv();
$dotEnv->addHandler(new SecretHandler(new FileKeyProvider('support/secret.key')), prepend: true);
$config = $dotEnv->loadPrivate('/path/to/app');
// In .env: DB_PASSWORD=secret(base64encryptedvalue)
```

## EXCEPTIONS
| Exception | Trigger | Extra |
|-----------|---------|-------|
| `CircularEnvIncludeException` | a.env → b.env → a.env | `getIncludeStack()` |
| `EnvFileNotFoundException` | required `load()` missing or `_FILE` path not found | `getFilePath()` |
| `EnvFileNotReadableException` | file not readable | `getFilePath()` |
| `EnvParseException` | syntax error (defined, not thrown currently) | `getFilePath()`, `getLineNumber()` |

NS: `JardisSupport\DotEnv\Exception` — all extend `DotEnvException`.

## RULES
- **NEVER:** DotEnv in domain; `loadPublic()` outside bootstrap; circular includes; `getenv()` directly for `.env` values
- **ALWAYS:** `loadPrivate()` for domain-specific config; `*Config` classes in `Infrastructure/Config/`; inject config as primitives into the domain; `load?()` for optional files; `prepend: true` for handlers that must run before variable substitution

## USAGE
```php
// Bootstrap
(new DotEnv())->loadPublic(__DIR__);

// Domain config
final class OrderConfig {
    private array $config;
    public function __construct() {
        $this->config = (new DotEnv())->loadPrivate(__DIR__ . '/../../config/orders');
    }
    public function maxOrderValue(): float { return $this->config['MAX_ORDER_VALUE']; }
}
```

## LAYER
- **Infrastructure/Bootstrap:** `loadPublic()`
- **Infrastructure/Config:** `loadPrivate()` → `*Config` classes
- **Domain:** NEVER imports DotEnv
