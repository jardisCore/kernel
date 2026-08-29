<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\Messaging\Config\DatabaseTransportOptions;
use JardisAdapter\Messaging\Connection\ExternalDatabaseConnection;
use JardisAdapter\Messaging\MessagePublisher;
use JardisAdapter\Messaging\Publisher\DatabasePublisher;
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Contract\Messaging\MessageHandlerInterface;
use JardisSupport\Contract\Messaging\MessagingServiceInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * `MESSAGING_TRANSPORT=database` through a REAL DotEnv cascade — the
 * broker-less transport (Transactional Outbox) that reuses the writer PDO the
 * packer already built from `DB_*` instead of opening a connection of its own.
 *
 * Unlike its sibling {@see BuildMessagingFromEnvTest} this suite needs **no
 * Docker broker at all**: the fixtures configure `DB_DRIVER=sqlite`,
 * `DB_PATH=:memory:`, so publisher and consumer share the one in-memory PDO
 * the kernel hands out through `dbConnection()`.
 *
 * The event tables are created here with **SQLite-dialect DDL** copied from
 * the adapter's own `tests/Unit/DatabasePublisherTest.php` — the adapter's
 * `src/Schema/domain_events.sql` is MySQL-only (BIGINT UNSIGNED
 * AUTO_INCREMENT, INDEX inside CREATE TABLE, ENGINE=InnoDB) and is the
 * reference schema for a real migration, never something a test executes.
 * Creating those tables is deliberately NOT the kernel's job (Säule 1) — the
 * project runs the migration, the handler only wires publisher and consumer.
 *
 * Fan-out consumer groups (`MESSAGING_DB_SUBSCRIPTION_TABLE`) are wired and
 * asserted as a passed-through option, but not exercised end-to-end — a
 * deliberate v1 non-goal (PRD queue-database, E3): the key must arrive at the
 * adapter, the fan-out behaviour itself is the adapter's own tested ground.
 */
final class BuildMessagingDatabaseFromEnvTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/ProjectRoot/' . $name;
    }

    // -- messaging() is built at all, off the project database --------------

    public function testDatabaseTransportBuildsMessagingServiceFromTheProjectDatabase(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingDatabase'));

        $pdo = $kernel->dbConnection();
        self::assertInstanceOf(PDO::class, $pdo);

        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        $publisher = $this->databasePublisher($messaging);
        $connection = $publisher->getConnection();
        self::assertInstanceOf(ExternalDatabaseConnection::class, $connection);

        // No second DSN: the transport runs on the very PDO the kernel's own
        // dbConnection() accessor hands to domain code.
        self::assertSame($pdo, $connection->getClient());
    }

    // -- publish() + consume() roundtrip against SQLite ---------------------

    public function testPublishAndConsumeRoundtripAgainstSqlite(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingDatabase'));

        $pdo = $kernel->dbConnection();
        self::assertInstanceOf(PDO::class, $pdo);
        $this->createEventTable($pdo);

        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        $topic = 'kernel-test.database.' . uniqid();
        self::assertTrue($messaging->publish($topic, 'hello-kernel-database'));

        $stmt = $pdo->query('SELECT COUNT(*) FROM domain_events WHERE processed_at IS NULL');
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn());

        $received = null;
        $messaging->consume($topic, $this->captureAndStopHandler($messaging, $received));

        self::assertSame('hello-kernel-database', $received);

        // Soft delete is the adapter default (deleteAfterProcessing=false):
        // the row survives and carries a processed_at timestamp.
        $stmt = $pdo->query('SELECT COUNT(*) FROM domain_events WHERE processed_at IS NOT NULL');
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    // -- error rule: explicitly chosen transport, mandatory config missing --

    public function testDatabaseTransportWithoutConfiguredDatabaseThrowsAtBoot(): void
    {
        // Same line as MESSAGING_TRANSPORT=rabbitmq without RABBITMQ_HOST:
        // an explicitly chosen transport whose mandatory config is missing is
        // a config error, never a silent degrade-to-null (that stays reserved
        // for an unset MESSAGING_TRANSPORT).
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessage('MESSAGING_TRANSPORT=database requires a configured database (DB_*).');

        (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingDatabaseWithoutDb'));
    }

    /**
     * A table name is an SQL identifier — the adapter interpolates it, it can
     * never be a bound placeholder. The guard therefore has to sit at the
     * boundary: `DatabaseTransportOptions` rejects anything outside
     * `[a-zA-Z_][a-zA-Z0-9_]*`, and this handler's existing
     * `InvalidArgumentException` catch turns that into the kernel's own
     * config exception — at boot, before any query is ever built.
     */
    public function testMalformedTableNameIsRejectedAtBoot(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessageMatches('/Invalid messaging configuration for transport "database"/');

        (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingDatabaseBadTable'));
    }

    // -- one test per MESSAGING_DB_* key (Wertetabelle = Test je Zeile) -----

    public function testTableKeyReachesTheAdapterOptions(): void
    {
        self::assertSame('outbox_events', $this->configuredOptions()->table);
    }

    public function testSubscriptionTableKeyReachesTheAdapterOptions(): void
    {
        self::assertSame('outbox_subscriptions', $this->configuredOptions()->subscriptionTable);
    }

    public function testDeleteAfterProcessingKeyReachesTheAdapterOptions(): void
    {
        // `MESSAGING_DB_DELETE_AFTER_PROCESSING=true` arrives from DotEnv's
        // cast chain as a real bool, not the string 'true' — NormalizeEnvBool.
        self::assertTrue($this->configuredOptions()->deleteAfterProcessing);
    }

    public function testPollingIntervalMsKeyReachesTheAdapterOptions(): void
    {
        self::assertSame(25, $this->configuredOptions()->pollingIntervalMs);
    }

    public function testBatchSizeKeyReachesTheAdapterOptions(): void
    {
        self::assertSame(7, $this->configuredOptions()->batchSize);
    }

    public function testMaxAttemptsKeyReachesTheAdapterOptions(): void
    {
        self::assertSame(9, $this->configuredOptions()->maxAttempts);
    }

    /**
     * The other half of the bool normalization: DotEnv casts `=1` to int(1),
     * which a naive `=== 'true'` string comparison would silently read as
     * false (the exact bug class NormalizeEnvBool exists for).
     */
    public function testDeleteAfterProcessingAcceptsTheNumericBoolForm(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingDatabaseDeleteNumeric'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        self::assertTrue($this->optionsOf($messaging)->deleteAfterProcessing);
    }

    /**
     * All six keys are optional — without any of them the adapter's own
     * defaults apply, and the kernel keeps no second default list.
     */
    public function testMissingKeysFallBackToTheAdapterDefaults(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingDatabase'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        $options = $this->optionsOf($messaging);
        $defaults = new DatabaseTransportOptions();

        self::assertSame($defaults->table, $options->table);
        self::assertSame($defaults->subscriptionTable, $options->subscriptionTable);
        self::assertSame($defaults->deleteAfterProcessing, $options->deleteAfterProcessing);
        self::assertSame($defaults->pollingIntervalMs, $options->pollingIntervalMs);
        self::assertSame($defaults->batchSize, $options->batchSize);
        self::assertSame($defaults->maxAttempts, $options->maxAttempts);
    }

    // -- helpers ------------------------------------------------------------

    private function configuredOptions(): DatabaseTransportOptions
    {
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('MessagingDatabaseOptions'));
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        return $this->optionsOf($messaging);
    }

    private function optionsOf(MessagingServiceInterface $messaging): DatabaseTransportOptions
    {
        $options = (new ReflectionProperty(DatabasePublisher::class, 'options'))
            ->getValue($this->databasePublisher($messaging));

        self::assertInstanceOf(DatabaseTransportOptions::class, $options);

        return $options;
    }

    private function databasePublisher(MessagingServiceInterface $messaging): DatabasePublisher
    {
        $facade = $messaging->getPublisher();
        self::assertInstanceOf(MessagePublisher::class, $facade);

        $publishers = (new ReflectionProperty(MessagePublisher::class, 'publishers'))->getValue($facade);
        self::assertIsArray($publishers);
        self::assertInstanceOf(DatabasePublisher::class, $publishers[0]);

        return $publishers[0];
    }

    /**
     * SQLite-dialect DDL, copied from the adapter's own
     * `tests/Unit/DatabasePublisherTest.php::createTable()` — see class
     * docblock for why `src/Schema/domain_events.sql` is not executed here.
     */
    private function createEventTable(PDO $pdo, string $table = 'domain_events'): void
    {
        $pdo->exec(
            "CREATE TABLE {$table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                topic VARCHAR(255) NOT NULL,
                payload TEXT NOT NULL,
                created_at TEXT NOT NULL,
                processed_at TEXT NULL DEFAULT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT NULL DEFAULT NULL
            )"
        );
    }

    /**
     * Captures the first message and stops the polling loop — the database
     * consumer polls forever otherwise (there is no "one message and done"
     * option); returning `true` is what marks the event processed.
     *
     * @param mixed $capturedInto Bound by reference; receives the payload.
     */
    private function captureAndStopHandler(
        MessagingServiceInterface $messaging,
        mixed &$capturedInto,
    ): MessageHandlerInterface {
        return new class ($messaging, $capturedInto) implements MessageHandlerInterface {
            public function __construct(
                private readonly MessagingServiceInterface $messaging,
                private mixed &$captured,
            ) {
            }

            public function handle(string|array $message, array $metadata): bool
            {
                $this->captured = $message;
                $this->messaging->getConsumer()->stop();

                return true;
            }
        };
    }
}
