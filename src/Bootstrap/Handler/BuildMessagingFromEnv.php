<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use InvalidArgumentException;
use JardisAdapter\Messaging\Config\DatabaseTransportOptions;
use JardisAdapter\Messaging\Connection\RabbitMqConnectionInterface;
use JardisAdapter\Messaging\Factory\ConnectionFactory;
use JardisAdapter\Messaging\Factory\ConsumerFactory;
use JardisAdapter\Messaging\Factory\PublisherFactory;
use JardisAdapter\Messaging\MessageConsumer;
use JardisAdapter\Messaging\MessagePublisher;
use JardisAdapter\Messaging\MessagingService;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Contract\Messaging\MessageConsumerInterface;
use JardisSupport\Contract\Messaging\MessageHandlerInterface;
use JardisSupport\Contract\Messaging\MessagingServiceInterface;
use PDO;
use RuntimeException;

/**
 * Builds a messaging service from ENV values.
 *
 * Requires jardisadapter/messaging (composer suggest). Its
 * ConnectionFactory/PublisherFactory/ConsumerFactory do all connection- and
 * publisher/consumer-building — this handler only reads canonical ENV keys
 * and dispatches by `MESSAGING_TRANSPORT` (G13: no own broker-list/port
 * parsing).
 *
 * `MESSAGING_TRANSPORT=kafka|rabbitmq|redis|database`; missing/empty -> null
 * (not configured); any other value -> {@see InvalidEnvConfigurationException}.
 *
 * Kafka: `KAFKA_BROKERS` carries the full comma-separated `host:port` list
 * unchanged into the factory's host field — `KAFKA_PORT` is not a canonical
 * key (G13, closes the Kafka-Falle from BEFUNDE.md §5: `ConnectionFactory::
 * kafka()` ignores a separate port). Redis: same `REDIS_*` keys as the cache
 * accessor (G23, one stack = one Redis) but its own `RedisConnection` — a
 * blocking consumer connection must never share the cache/logger client.
 *
 * Redis uses Streams (`useStreams: true`), not Pub/Sub — measured directly
 * against a real broker (not assumed): Pub/Sub's `subscribe()` only reaches
 * subscribers already listening at publish time (a message published a
 * moment too early is gone, no queue behind it), and phpredis's own
 * subscribe-loop socket handling proved unreliable under repeated
 * publish-then-consume roundtrips in this environment — sometimes delivering
 * within seconds, sometimes never returning at all, identical code and
 * container. Streams give at-least-once delivery independent of subscriber
 * timing, matching the durability Kafka/RabbitMQ already provide as queued
 * transports — the more defensible default for a general messaging accessor
 * when no canonical key picks between the two.
 *
 * Database is the broker-less transport (Transactional Outbox): it does not
 * open a connection of its own and has no `MESSAGING_DB_DSN`-style key —
 * it REUSES the writer PDO the packer already built from `DB_*`
 * ({@see ExtractPdoFromConnection}, handed in as this handler's `$pdo`
 * argument) via `ConnectionFactory::fromPdo(..., manageLifecycle: false)`:
 * one stack, one database, and the messaging side never closes a handle the
 * domain code still uses. Table/polling/retry behaviour comes from the six
 * optional `MESSAGING_DB_*` keys, each defaulting to the adapter's own
 * {@see DatabaseTransportOptions} default (no second default list here).
 * Consumer semantics stay the adapter's Point-to-Point default; fan-out
 * consumer groups are a per-`consume()` option, not an ENV key.
 *
 * Why database SHARES its connection while redis deliberately does not: the
 * Redis consumer parks the socket inside a blocking `XREAD ... BLOCK` call,
 * so a shared client would starve the cache and logger for the whole window.
 * The database consumer holds no blocking call — it runs short polling
 * queries and `usleep()`s between them, releasing the handle each time — so
 * reusing the one writer PDO costs nothing and keeps "one stack, one
 * database" intact. `manageLifecycle: false` guarantees the messaging side
 * never disconnects it.
 *
 * Table names reach the adapter as SQL identifiers (they are interpolated,
 * not bound — a table name cannot be a placeholder). Validation is the
 * adapter's, at the boundary: `DatabaseTransportOptions` rejects anything
 * outside `[a-zA-Z_][a-zA-Z0-9_]*` with an `InvalidArgumentException`, which
 * this handler's existing catch turns into an
 * {@see InvalidEnvConfigurationException} at boot — a malformed
 * `MESSAGING_DB_TABLE` never reaches a query.
 *
 * Failure mode: `MESSAGING_TRANSPORT=database` with no usable writer PDO
 * (no `DB_*` at all, or a connection shape carrying none) throws
 * {@see InvalidEnvConfigurationException} — the SAME line the explicitly
 * chosen rabbitmq/redis transports already follow ("explicitly chosen +
 * mandatory config missing is a config error"). Degrade-to-null stays
 * reserved for "not configured at all", i.e. an unset `MESSAGING_TRANSPORT`.
 * Creating the event tables is NOT this handler's job (Säule 1) — the
 * adapter ships the MySQL reference schema (`src/Schema/domain_events.sql`),
 * the project runs it as a migration.
 *
 * Two different things are lazy vs. eager here, deliberately not the same
 * boundary (G5 rule 2 vs. rule 3):
 * - **Eager (this handler, at boot):** the connection OBJECT for the chosen
 *   transport (`ConnectionFactory::kafka()`/`rabbitMq()`/`redis()`, and
 *   `fromPdo()` for database) is built right here, inside
 *   `buildKafka()`/`buildRabbitMq()`/`buildRedis()`/`buildDatabase()` —
 *   never inside a lazy closure. Building it does no I/O (`connect()` is a
 *   separate step the adapter's Publisher/Consumer classes call lazily), but
 *   it DOES run `ConnectionConfig`'s constructor validation (non-empty host,
 *   port range) immediately, and this handler additionally rejects a
 *   configured-but-missing required field itself ({@see IsEnvValueUnset}):
 *   `KAFKA_BROKERS` for kafka, `RABBITMQ_HOST`/`REDIS_HOST` for the next
 *   two (no default — an implicit "localhost" would silently hide a missing
 *   value the same way `KAFKA_BROKERS` used to), and a usable writer PDO for
 *   database. Both failure kinds surface
 *   as {@see InvalidEnvConfigurationException} the moment `messaging()` is
 *   first called on the packed kernel, not on first `publish()`.
 * - **Lazy (the adapter's own `MessagingService`):** the actual
 *   `publish()`/`consume()` — and therefore the real `connect()` and any
 *   "configured but unreachable" failure — stays deferred to first use,
 *   preserving the adapter's own lazy-construction design.
 *
 * RabbitMQ has no canonical queue-name key (PRD/PLAN only name
 * `RABBITMQ_HOST/PORT/USER/PASSWORD`) while `ConsumerFactory::rabbitMq()`
 * binds one fixed queue at construction time, ahead of the topic the
 * generic `consume(string $topic, ...)` call carries. This handler resolves
 * that by using the topic itself as the RabbitMQ queue name — the same
 * uniform "topic names the destination" contract Kafka and Redis already
 * give the caller — deferring the actual `ConsumerFactory::rabbitMq()` call
 * until the first `consume()` invocation supplies it.
 *
 * Consuming Kafka needs a consumer group ID; the ENV bootstrap has no
 * `MESSAGING_*` key for it (G14, deliberately out of scope) — the returned
 * service's `consume()` throws a clear `RuntimeException`, `publish()`
 * stays unaffected (lazy per-capability construction).
 */
final class BuildMessagingFromEnv
{
    private const VALID_TRANSPORTS = ['kafka', 'rabbitmq', 'redis', 'database'];

    /**
     * @param Closure(string): mixed $env
     * @param ?PDO $pdo The writer PDO the packer already built from `DB_*`
     *                  ({@see ExtractPdoFromConnection}) — only the database
     *                  transport uses it; the broker transports ignore it.
     */
    public function __invoke(Closure $env, ?PDO $pdo = null): ?MessagingServiceInterface
    {
        if (!class_exists(ConnectionFactory::class)) {
            // @codeCoverageIgnoreStart
            // jardisadapter/messaging is a require-dev dependency of this very
            // test suite (established pattern, see BuildCacheFromEnv /
            // BuildConnectionPoolConfigFromEnv) — structurally unreachable here.
            return null;
            // @codeCoverageIgnoreEnd
        }

        $transportRaw = $env('messaging_transport');
        if ((new IsEnvValueUnset())($transportRaw)) {
            return null;
        }

        $transport = strtolower((string) $transportRaw);
        if (!in_array($transport, self::VALID_TRANSPORTS, true)) {
            throw new InvalidEnvConfigurationException(sprintf(
                'Invalid MESSAGING_TRANSPORT "%s": expected one of %s.',
                (string) $transportRaw,
                implode(', ', self::VALID_TRANSPORTS),
            ));
        }

        $connectionFactory = new ConnectionFactory();
        $publisherFactory = new PublisherFactory();
        $consumerFactory = new ConsumerFactory();

        try {
            return match ($transport) {
                'kafka' => $this->buildKafka($env, $connectionFactory, $publisherFactory),
                'rabbitmq' => $this->buildRabbitMq($env, $connectionFactory, $publisherFactory, $consumerFactory),
                'redis' => $this->buildRedis($env, $connectionFactory, $publisherFactory, $consumerFactory),
                'database' => $this->buildDatabase(
                    $env,
                    $pdo,
                    $connectionFactory,
                    $publisherFactory,
                    $consumerFactory,
                ),
            };
        } catch (InvalidArgumentException $e) {
            throw new InvalidEnvConfigurationException(
                sprintf('Invalid messaging configuration for transport "%s": %s', $transport, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /** @param Closure(string): mixed $env */
    private function buildKafka(
        Closure $env,
        ConnectionFactory $connectionFactory,
        PublisherFactory $publisherFactory,
    ): MessagingServiceInterface {
        $brokers = $env('kafka_brokers');
        if ((new IsEnvValueUnset())($brokers)) {
            throw new InvalidEnvConfigurationException(
                'MESSAGING_TRANSPORT=kafka requires KAFKA_BROKERS (comma-separated host:port list).'
            );
        }

        // KAFKA_USER, not KAFKA_USERNAME: matches CredentialEnvKeySuffixes'
        // "_USER" suffix, so DotEnv registers it as a raw key (survives the
        // cast chain as a string) — the same protection RABBITMQ_USER
        // already gets. A "_USERNAME" suffix would silently miss that match.
        $username = $this->stringOrNull($env('kafka_user'));
        $password = $this->stringOrNull($env('kafka_password'));

        // Eager (class docblock): validates the broker-list shape now via
        // ConnectionConfig's constructor — no connect() happens here.
        $connection = $connectionFactory->kafka((string) $brokers, $username, $password);

        return new MessagingService(
            publisherFactory: static fn () => new MessagePublisher($publisherFactory->kafka($connection)),
            consumerFactory: static fn () => throw new RuntimeException(
                'Kafka consuming requires a consumer group ID. The ENV bootstrap has no '
                . 'MESSAGING_* key for it (G14) — build a KafkaConsumerConnection via '
                . 'ConnectionFactory::kafkaConsumer($brokers, $groupId) directly instead of '
                . 'DomainKernel::messaging()->consume() for this transport.'
            ),
        );
    }

    /** @param Closure(string): mixed $env */
    private function buildRabbitMq(
        Closure $env,
        ConnectionFactory $connectionFactory,
        PublisherFactory $publisherFactory,
        ConsumerFactory $consumerFactory,
    ): MessagingServiceInterface {
        $host = $env('rabbitmq_host');
        if ((new IsEnvValueUnset())($host)) {
            throw new InvalidEnvConfigurationException('MESSAGING_TRANSPORT=rabbitmq requires RABBITMQ_HOST.');
        }

        $port = (int) ($env('rabbitmq_port') ?? 5672);
        $username = (string) ($env('rabbitmq_user') ?? 'guest');
        $password = (string) ($env('rabbitmq_password') ?? 'guest');

        // Eager (class docblock): validates host/port shape now via
        // ConnectionConfig's constructor — no connect() happens here.
        $connection = $connectionFactory->rabbitMq((string) $host, $port, $username, $password);

        return new MessagingService(
            publisherFactory: static fn () => new MessagePublisher($publisherFactory->rabbitMq($connection)),
            consumerFactory: static fn () => new class (
                $connection,
                $consumerFactory
            ) implements MessageConsumerInterface {
                private ?MessageConsumerInterface $inner = null;

                public function __construct(
                    private readonly RabbitMqConnectionInterface $connection,
                    private readonly ConsumerFactory $factory,
                ) {
                }

                public function consume(string $topic, MessageHandlerInterface $handler, array $options = []): void
                {
                    $this->resolve($topic)->consume($topic, $handler, $options);
                }

                public function stop(): void
                {
                    $this->inner?->stop();
                }

                private function resolve(string $topic): MessageConsumerInterface
                {
                    if ($this->inner === null) {
                        // The queue name is only known once the first topic
                        // arrives — no canonical RABBITMQ_QUEUE key exists
                        // (see class docblock), so the topic doubles as the
                        // queue name, uniform with Kafka/Redis.
                        $this->inner = new MessageConsumer($this->factory->rabbitMq($this->connection, $topic));
                    }

                    return $this->inner;
                }
            },
        );
    }

    /** @param Closure(string): mixed $env */
    private function buildRedis(
        Closure $env,
        ConnectionFactory $connectionFactory,
        PublisherFactory $publisherFactory,
        ConsumerFactory $consumerFactory,
    ): MessagingServiceInterface {
        $host = $env('redis_host');
        if ((new IsEnvValueUnset())($host)) {
            throw new InvalidEnvConfigurationException('MESSAGING_TRANSPORT=redis requires REDIS_HOST.');
        }

        $port = (int) ($env('redis_port') ?? 6379);
        $password = $this->stringOrNull($env('redis_password'));

        // Eager (class docblock): validates host/port shape now via
        // ConnectionConfig's constructor — no connect() happens here. Two
        // separate connection objects, matching the two different timeout
        // needs below (publisher: short default; consumer: see comment).
        $publisherConnection = $connectionFactory->redis((string) $host, $port, $password);
        // phpredis reuses its connect() "timeout" as the blocking-command
        // read timeout too — `XREAD ... BLOCK` legitimately waits longer
        // than a publish call ever does, so the default 2s (RedisConnection's
        // own fallback) would make a longer `block` option throw a client-side
        // read error before the server's own BLOCK window elapses. `0` is
        // phpredis's own "use the ini default" convention (consumer-only,
        // the publisher keeps the short default).
        $consumerConnection = $connectionFactory->redis((string) $host, $port, $password, ['timeout' => 0]);

        return new MessagingService(
            publisherFactory: static fn () => new MessagePublisher(
                $publisherFactory->redis($publisherConnection, useStreams: true)
            ),
            consumerFactory: static fn () => new MessageConsumer(
                $consumerFactory->redis($consumerConnection, useStreams: true)
            ),
        );
    }

    /** @param Closure(string): mixed $env */
    private function buildDatabase(
        Closure $env,
        ?PDO $pdo,
        ConnectionFactory $connectionFactory,
        PublisherFactory $publisherFactory,
        ConsumerFactory $consumerFactory,
    ): MessagingServiceInterface {
        if ($pdo === null) {
            throw new InvalidEnvConfigurationException(
                'MESSAGING_TRANSPORT=database requires a configured database (DB_*).'
            );
        }

        $options = $this->databaseOptions($env);

        // Eager (class docblock): wraps the writer PDO the packer already
        // built — no second connect() and, with manageLifecycle: false, no
        // disconnect() either; the domain's own connection stays in charge.
        $connection = $connectionFactory->fromPdo($pdo, manageLifecycle: false);

        return new MessagingService(
            publisherFactory: static fn () => new MessagePublisher(
                $publisherFactory->database($connection, $options)
            ),
            consumerFactory: static fn () => new MessageConsumer(
                $consumerFactory->database($connection, $options)
            ),
        );
    }

    /**
     * Maps the six optional `MESSAGING_DB_*` keys onto the adapter's own
     * option object. Every missing key falls back to the ADAPTER's default
     * (read off a default-constructed instance) — this handler keeps no
     * second default list that could drift from it.
     *
     * @param Closure(string): mixed $env
     */
    private function databaseOptions(Closure $env): DatabaseTransportOptions
    {
        $defaults = new DatabaseTransportOptions();

        $deleteAfterProcessing = (new NormalizeEnvBool())(
            $env('messaging_db_delete_after_processing'),
            'MESSAGING_DB_DELETE_AFTER_PROCESSING',
        );

        return new DatabaseTransportOptions(
            table: (string) ($env('messaging_db_table') ?? $defaults->table),
            subscriptionTable: (string) ($env('messaging_db_subscription_table') ?? $defaults->subscriptionTable),
            deleteAfterProcessing: $deleteAfterProcessing ?? $defaults->deleteAfterProcessing,
            pollingIntervalMs: (int) ($env('messaging_db_polling_interval_ms') ?? $defaults->pollingIntervalMs),
            batchSize: (int) ($env('messaging_db_batch_size') ?? $defaults->batchSize),
            maxAttempts: (int) ($env('messaging_db_max_attempts') ?? $defaults->maxAttempts),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
