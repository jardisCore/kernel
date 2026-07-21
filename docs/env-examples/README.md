# ENV Templates for `BuildDomainKernelFromEnv`

These files are templates, not live config — copy the ones you need into your
App config root (the directory you pass to
`(new BuildDomainKernelFromEnv())($configPath)`) and strip the `.example`
suffix. `BuildDomainKernelFromEnv` loads that directory via
`JardisSupport\DotEnv\DotEnv::loadPrivate()` — the same `load()` / `load?()`
cascade every other Jardis config file understands (required includes throw
`EnvFileNotFoundException` if missing; `load?()` includes are silently
skipped).

Content revised from the Builder's former `SharedRuntime/` ENV generat
(`tools/builder tests/Builder/Generated/Domain/SharedRuntime`, read-only
source for this revision) — that generat used stale, unread ENV keys
(`DB_WRITER_*`, `CACHE_REDIS_ENABLED`, …, and a `Messaging` section this
kernel does not build). The keys below are the ones the ten
`Bootstrap/Handler/*` closures actually read — **ten closures, not eleven**:
`ls src/Bootstrap/Handler/*.php` → 10 files. Eleven is the packed
`DomainKernel`'s **accessor** count instead (`grep -cE "public function
[a-z]" src/DomainKernel.php` → 11) — a different layer (the Koffer's read
side) from the Bootstrap-Packer's build side documented here. Don't conflate
the two counts.

## Files

| Template | Feeds | Required to activate |
|---|---|---|
| `.env.example` | cascade root — `load()`s the others | copy first, adjust `load?()` list to what you use |
| `.env.database.example` | `BuildConnectionFromEnv` | `DB_HOST` (or `DB_DRIVER=sqlite`) |
| `.env.redis.example` | `BuildRedisFromEnv` — shared by cache + logger (fan-out, D4) | `REDIS_HOST` |
| `.env.cache.example` | `BuildCacheFromEnv` (requires `jardisadapter/cache`) | `CACHE_LAYERS` |
| `.env.logger.example` | `BuildLoggerFromEnv` (requires `jardisadapter/logger`) | `LOG_HANDLERS` |
| `.env.http.example` | `BuildHttpClientFromEnv` (requires `jardisadapter/http`) | none — works with zero config once the adapter is installed |
| `.env.mail.example` | `BuildMailerFromEnv` (requires `jardisadapter/mailer`) | `MAIL_HOST` |

Four of the ten Bootstrap handlers need no ENV at all and have no template
here: `BuildFilesystemFromEnv` (stateless factory) and
`BuildEventListenerProviderFromEnv` / `BuildEventDispatcherFromProvider` (pure
in-memory pair, D3) activate purely by the corresponding adapter package
being installed (`jardisadapter/filesystem`, `jardisadapter/eventdispatcher`);
`ExtractPdoFromConnection` is the fourth — see "No ENV at all" below for why
it's a different case (not adapter-gated, a pure data-transform closure).

Every value here is optional — an unconfigured service resolves to `null` on
the packed `DomainKernel` (see "Two states, not three" below). See
`README.md` (package root) section "DomainKernel — the Koffer" for the packed
accessor list.

## ENV keys reference

Every key any `Bootstrap/Handler/*` closure reads, grouped by handler. This is
the doc list AC A6 diffs against the source — see "Verifying this list" below.

### `BuildConnectionFromEnv` (`.env.database.example`)

| Key | Default | Notes |
|---|---|---|
| `DB_DRIVER` | `mysql` | `mysql` \| `pgsql` \| `sqlite` |
| `DB_HOST` | — | required for `mysql`/`pgsql`; unset ⇒ `null` (no connection) unless `DB_DRIVER=sqlite` |
| `DB_PORT` | `3306` (`5432` for `pgsql`) | |
| `DB_USER` | `root` | |
| `DB_PASSWORD` | `''` | |
| `DB_DATABASE` | `''` | |
| `DB_CHARSET` | `utf8mb4` (`utf8` for `pgsql`) | |
| `DB_PATH` | `:memory:` | **read only when `DB_DRIVER=sqlite`** — overrides the host/port/user block above for that driver |
| `DB_READER{N}_HOST` | — | numbered read replica (`DB_READER1_HOST`, `DB_READER2_HOST`, …); presence of `_HOST` is what registers reader *N* |
| `DB_READER{N}_PORT` | writer's `DB_PORT` | falls back to the writer value if unset |
| `DB_READER{N}_USER` | writer's `DB_USER` | falls back to the writer value if unset |
| `DB_READER{N}_PASSWORD` | writer's `DB_PASSWORD` | falls back to the writer value if unset |
| `DB_READER{N}_DATABASE` | writer's `DB_DATABASE` | falls back to the writer value if unset |

At least one `DB_READER{N}_HOST` plus `jardisadapter/dbconnection` installed
(`class_exists(ConnectionPool::class)`) builds a `ConnectionPool`; otherwise a
plain `PDO` on `DB_HOST` is returned. Code:
`src/Bootstrap/Handler/BuildConnectionFromEnv.php:35-160` (reader family
specifically `:135-160`, `DB_PATH` specifically `:57`).

### `BuildRedisFromEnv` (`.env.redis.example`)

| Key | Default | Notes |
|---|---|---|
| `REDIS_HOST` | — | unset ⇒ `null` (no Redis connection at all) |
| `REDIS_PORT` | `6379` | |
| `REDIS_PASSWORD` | — | `AUTH` only sent if set and non-empty |
| `REDIS_DATABASE` | — | `SELECT` only sent if set |

One connection feeds both the cache `redis` layer and the logger `redis`
handler (fan-out, D4) — there is no separate per-consumer Redis config. Code:
`src/Bootstrap/Handler/BuildRedisFromEnv.php:23-50`.

### `BuildCacheFromEnv` (`.env.cache.example`, requires `jardisadapter/cache`)

| Key | Default | Notes |
|---|---|---|
| `CACHE_NAMESPACE` | `null` | |
| `CACHE_LAYERS` | — (empty list) | comma-separated, tried in order; unknown names are skipped, not an error — see `CacheLayer` below |
| `CACHE_DB_TABLE` | `cache` | only read for the `db` layer |

Code: `src/Bootstrap/Handler/BuildCacheFromEnv.php:43-65`.

### `BuildLoggerFromEnv` (`.env.logger.example`, requires `jardisadapter/logger`)

| Key | Default | Notes |
|---|---|---|
| `LOG_HANDLERS` | — | unset ⇒ `null` (no logger at all); comma-separated `handler[:LEVEL]` list — see `LogHandler` below |
| `LOG_CONTEXT` | `app` | |
| `LOG_LEVEL` | `INFO` | fallback level when an entry in `LOG_HANDLERS` omits `:LEVEL` |
| `LOG_FILE_PATH` | `/var/log/app.log` | only read for the `file` handler |
| `LOG_SLACK_URL` | — | only read for the `slack` handler; unset/empty ⇒ handler skipped, no error |
| `LOG_TEAMS_URL` | — | same pattern, `teams` handler |
| `LOG_LOKI_URL` | — | same pattern, `loki` handler |
| `LOG_WEBHOOK_URL` | — | same pattern, `webhook` handler |

Code: `src/Bootstrap/Handler/BuildLoggerFromEnv.php:30-103`.

### `BuildHttpClientFromEnv` (`.env.http.example`, requires `jardisadapter/http`)

| Key | Default | Notes |
|---|---|---|
| `HTTP_TIMEOUT` | `30` | |
| `HTTP_CONNECT_TIMEOUT` | `10` | |
| `HTTP_BASE_URL` | `null` | |
| `HTTP_VERIFY_SSL` | `true` | string comparison — anything other than the literal `true` is treated as false |
| `HTTP_BEARER_TOKEN` | `null` | |
| `HTTP_BASIC_USER` | `null` | |
| `HTTP_BASIC_PASSWORD` | `null` | |
| `HTTP_MAX_RETRIES` | `0` | **not** `3` — see §3.7 of the project PRD; `0` is the correct, unchanged default ported from `foundation` |
| `HTTP_RETRY_DELAY_MS` | `100` | |

No key here is required — the client builds with zero config once
`jardisadapter/http` is installed. Code:
`src/Bootstrap/Handler/BuildHttpClientFromEnv.php:24-58`.

### `BuildMailerFromEnv` (`.env.mail.example`, requires `jardisadapter/mailer`)

| Key | Default | Notes |
|---|---|---|
| `MAIL_HOST` | — | unset ⇒ `null` (no mailer at all) |
| `MAIL_PORT` | `587` | |
| `MAIL_ENCRYPTION` | `tls` | |
| `MAIL_USERNAME` | `null` | |
| `MAIL_PASSWORD` | `null` | |
| `MAIL_TIMEOUT` | `30` | |
| `MAIL_FROM_ADDRESS` | `null` | |
| `MAIL_FROM_NAME` | `null` | |

Code: `src/Bootstrap/Handler/BuildMailerFromEnv.php:24-51`.

### No ENV at all

Four of the ten `Bootstrap/Handler/*` closures read no ENV key at all.
`BuildFilesystemFromEnv`, `BuildEventListenerProviderFromEnv` and
`BuildEventDispatcherFromProvider` activate purely on the matching adapter
package being installed (`class_exists(...)`). `ExtractPdoFromConnection`
(`src/Bootstrap/Handler/ExtractPdoFromConnection.php:20-27`) is the fourth,
and a different kind of case: it isn't an ENV-to-service builder at all, but
a pure data-transform closure — it extracts a plain `PDO` handle from the
connection `BuildConnectionFromEnv` already built, feeding `BuildCacheFromEnv`'s
`db` layer regardless of whether the domain runs on a bare connection or a
`ConnectionPool`. No ENV key belongs to it because there is nothing for it to
read; the ENV it depends on was already consumed upstream by
`BuildConnectionFromEnv`.

### Verifying this list

```bash
grep -rhoE "env\('[a-z0-9_]+'\)" src/Bootstrap/Handler/*.php | sed -E "s/.*'([a-z0-9_]+)'.*/\1/"
grep -rhoE 'env\("db_reader\{\$i\}_[a-z]+"\)' src/Bootstrap/Handler/*.php | sed -E 's/.*"(.*)".*/\1/' | sed 's/{\$i}/{N}/'
grep -rhoE "prefix \. '[a-z0-9_]+'" src/Bootstrap/Handler/*.php | sed -E "s/.*'([a-z0-9_]+)'.*/\1/" | sed 's/^/redis_/'
grep -rhoE "'log_[a-z_]+_url'" src/Bootstrap/Handler/*.php | tr -d "'"
```
Run from `core/kernel` (each line piped through `tr 'a-z' 'A-Z' | sort -u`,
45 total, and compared against the tables above). Deliberately drop the
leading `$` from every pattern above (`env(` / `prefix .`, not `$env(` /
`$prefix .`) — inside a double-quoted bash string, `\$` is consumed by
bash's own escaping before grep ever sees it (a single backslash yields a
bare, unescaped `$` — which GNU grep's ERE then fails to match literally
outside the pattern's true end), and this file's code fences are exactly the
double-quoted strings a copy-pasting reader would run. `env(`/`prefix .` are
distinctive enough substrings on their own; no `$`, no escaping puzzle, no
silent zero-result. Separately: use `grep -r`, never `find | xargs grep -c`
— the latter drops the filename prefix whenever exactly one file matches,
which an `awk -F:`-style summation then silently reads as zero.

## Cache layers and log handlers

`CACHE_LAYERS` and `LOG_HANDLERS` entries are validated against two enums
under `src/Bootstrap/Data/` — neither is referenced by the "Files" table
above, and neither appeared anywhere in this doc before this revision:

- **`CacheLayer`** (`src/Bootstrap/Data/CacheLayer.php:13-19`) — `memory` |
  `apcu` | `redis` | `db`. Read by `BuildCacheFromEnv`
  (`src/Bootstrap/Handler/BuildCacheFromEnv.php:54`); an unrecognized name is
  skipped, not an error.
- **`LogHandler`** (`src/Bootstrap/Data/LogHandler.php:13-26`) — `file` |
  `console` | `errorlog` | `syslog` | `browserconsole` | `redis` | `slack` |
  `teams` | `loki` | `webhook` | `null`. Read by `BuildLoggerFromEnv`
  (`src/Bootstrap/Handler/BuildLoggerFromEnv.php:43`); same skip-on-unknown
  behaviour.

## Two states, not three — the `false` → `null` break

`jardiscore/foundation`'s `JardisApp` accessors returned
`Interface|false|null` — three states, e.g.
`protected function cache(): CacheInterface|false|null` (archived,
`src/JardisApp.php:48`, same pattern on lines 38/61/66/71/76/81 for the other
six services). The base hook these overrode documented `false` explicitly as
"explicitly disable \[this service\] for this DomainApp, ignore \[the\]
shared \[fallback\]" (archived vendored copy,
`vendor/jardiscore/kernel/src/DomainApp.php:204-330`).

**That third state is removed, not deprecated.** Every
`Bootstrap/Handler/*` closure in this package and every `DomainKernel`
accessor returns `?Interface` — two states only, an instance or `null`.
Belege: `DomainKernel::eventDispatcher(): ?EventDispatcherInterface`
(`src/DomainKernel.php:81-84`) and identically-shaped signatures for
`cache()` (`:71-74`), `logger()` (`:76-79`), `eventListenerRegistry()`
(`:86-89`), `httpClient()` (`:91-94`), `mailer()` (`:101-104`), `filesystem()`
(`:106-109`); `dbConnection()` (`:96-99`) is
`ConnectionPoolInterface|PDO|null` — same two-state shape, `PDO`/pool
replacing the single interface type. `BuildEventDispatcherFromProvider`
(`src/Bootstrap/Handler/BuildEventDispatcherFromProvider.php:24-31`) is
representative of every handler: it returns `?EventDispatcherInterface`,
never `false`.

There is no ENV key or mechanism in `core/kernel` to force a service "off"
while a shared fallback stays reachable elsewhere — the only way a service
resolves to `null` is that it was never configured (no matching ENV key, or
the adapter package not installed). Any doc that still describes a
three-state `Instance|false|null` contract for a kernel-v2 accessor describes
removed `foundation`-era behaviour, not this package.

## `APP_DEBUG`

The only key in this Bootstrap-Packer's ENV landscape with App-, not
Kernel-semantics. `core/kernel` neither reads nor validates it — like any
other key, it only becomes visible through
`DomainKernel::env('app_debug')` (`src/DomainKernel.php:60-64`), the same
private-ENV-then-`$_ENV` lookup every other key here goes through. There is
no `Bootstrap/Handler/*` closure for it, and it feeds nothing on the packed
`DomainKernel`. Its meaning — gating the generic-vs-detailed error response —
is defined and documented by `jardiscore/app` (`core/app/docs/getting-started.md`,
`AppConfig`'s `debug` flag); not repeated here.

## Example: minimal SQLite + console-log setup

```
DB_DRIVER=sqlite
DB_PATH=:memory:

LOG_HANDLERS=console
LOG_CONTEXT=app
LOG_LEVEL=DEBUG
```
