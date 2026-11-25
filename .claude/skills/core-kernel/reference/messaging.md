### `messaging()` — canonical ENV keys

`MESSAGING_TRANSPORT=kafka|rabbitmq|redis|database`; missing/empty → `null`
(not configured); any other value → `InvalidEnvConfigurationException`. No own
broker-list/port parsing (G13) — `Bootstrap\Handler\BuildMessagingFromEnv`
dispatches straight into `jardisadapter/messaging`'s
`ConnectionFactory`/`PublisherFactory`/`ConsumerFactory`.

| Transport | Required | Optional | Notes |
|---|---|---|---|
| `kafka` | `KAFKA_BROKERS` (comma-separated `host:port` list, unchanged into the factory's host field — `KAFKA_PORT` is not canonical) | `KAFKA_USER`, `KAFKA_PASSWORD` (raw-key protected, `_USER` not `_USERNAME`) | consuming needs a group ID with no canonical key (G14) — `consume()` throws a clear `RuntimeException`; `publish()` is unaffected |
| `rabbitmq` | `RABBITMQ_HOST` | `RABBITMQ_PORT` (5672), `RABBITMQ_USER`/`RABBITMQ_PASSWORD` (guest/guest) | no canonical queue-name key — the `consume(string $topic, ...)` topic doubles as the queue name |
| `redis` | `REDIS_HOST` | `REDIS_PORT` (6379), `REDIS_PASSWORD` | same `REDIS_*` keys as the cache accessor (one stack = one Redis), but its own dedicated connections; uses Streams (`useStreams: true`), not Pub/Sub — measured against a real broker, Pub/Sub loses messages published before a subscriber is listening |
| `database` | a usable `DB_*` connection (no key of its own) | `MESSAGING_DB_TABLE` (`domain_events`), `MESSAGING_DB_SUBSCRIPTION_TABLE` (`domain_event_subscriptions`), `MESSAGING_DB_DELETE_AFTER_PROCESSING` (`false`, via `NormalizeEnvBool`), `MESSAGING_DB_POLLING_INTERVAL_MS` (`1000`), `MESSAGING_DB_BATCH_SIZE` (`10`), `MESSAGING_DB_MAX_ATTEMPTS` (`3`) | broker-less Transactional Outbox; **reuses the writer PDO** the packer already built (`ExtractPdoFromConnection`, same handle the `db` cache layer gets) via `ConnectionFactory::fromDbConnection(provider, manageLifecycle: false)` — no second DSN, no `MESSAGING_DB_DSN` key; `consume()` works (polls the table), Point-to-Point by default, fan-out is a per-call `['group' => …]` option; **tables are a migration, not a kernel task** (adapter ships the MySQL reference schema `src/Schema/domain_events.sql`) |

`database` chosen with no usable writer PDO (no `DB_*` at all) throws
`InvalidEnvConfigurationException` — `MESSAGING_TRANSPORT=database requires a
configured database (DB_*).` — the same line rabbitmq/redis already follow:
degrade-to-null is reserved for an unset `MESSAGING_TRANSPORT`, never for an
explicitly chosen transport whose mandatory config is missing.

**Eager vs. lazy, deliberately not the same boundary:** the connection
OBJECT for the kafka/rabbitmq/redis transport is built eagerly inside the packer — no
network I/O, but `ConnectionConfig`'s own constructor validation (host/port
shape) and this handler's own presence check run immediately, so
`InvalidEnvConfigurationException` surfaces the moment `messaging()` is
first called on the packed kernel, not on first `publish()`. The actual
`connect()`/`publish()`/`consume()` — and therefore any "configured but
unreachable" failure — stays deferred to first use (the adapter's own lazy
design). The `database` transport is lazy one step further: it receives the
writer-PDO provider and hands it to `ConnectionFactory::fromDbConnection()`
(messaging >= 1.1), which resolves it at the first publish/consume, so
`messaging()` opens no database connection.
