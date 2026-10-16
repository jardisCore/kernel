<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\Logger\Handler\LogRedis;
use JardisAdapter\Logger\Handler\LogSyslog;
use JardisAdapter\Logger\Logger;
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\CapturesErrorLog;
use JardisSupport\Contract\Messaging\Exception\ConsumerException;
use JardisSupport\Contract\Messaging\Exception\PublishException;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\Messaging\MessageHandlerInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Central comparison test of the "all services lazy" invariant
 * (Bescheid Rolf 2026-10-15: generell lazy, alle Dienste):
 *
 *   No bootstrap path opens a connection or a file. The first functional access
 *   opens it, and an error appears THERE — not while the kernel is built.
 *
 * Paths covered: a SQLite file that does not exist yet (cache `db` layer and
 * `database` messaging transport, publish and consume), an unreachable Redis
 * host (cache `redis` layer, `redis` log handler), and one kernel with every
 * service configured at once (Redis in cache and logger, syslog, mailer, auth).
 * The resources are handed to the adapters as providers
 * (`CacheDatabase`/`CacheRedis`, `LoggerBuilder::addRedis()`,
 * `ConnectionFactory::fromDbConnection()` — cache/logger/messaging >= 1.1);
 * the kernel holds no lazy mechanism of its own for them.
 *
 * Exception type at the first access, per service:
 *  - database path unopenable: cache -> RuntimeException (from dbconnection,
 *    unchanged); messaging `publish()` -> PublishException and `consume()` ->
 *    ConsumerException, both carrying the adapter's message (messaging >= 1.1.1
 *    raises a ConnectionException with the adapter error as `previous` at the
 *    connection layer; the message publisher/consumer folds it into the
 *    layer-failed exception).
 *  - Redis unreachable: cache -> InvalidEnvConfigurationException (unchanged
 *    from the former bootstrap-time failure); logger -> the handler does not
 *    swallow it ({@see LogRedis::getRedis()}), `Logger::log()` hands it to the
 *    logger's error handler, which the kernel sets: the failure is reported
 *    via `error_log()`, logging itself never throws into the caller.
 */
final class LazyBootstrapInvariantTest extends TestCase
{
    use CapturesErrorLog;

    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/kernel-lazy-invariant-' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $file) {
            @unlink($file);
        }
    }

    private function env(string $dbPath, string $cacheLayers = 'db'): string
    {
        return "DB_DRIVER=sqlite\nDB_PATH={$dbPath}\n"
            . "REDIS_HOST=nonexistent_host_that_does_not_exist\nREDIS_PORT=6379\n"
            . "CACHE_LAYERS={$cacheLayers}\nCACHE_DB_TABLE=cache\n"
            . "MESSAGING_TRANSPORT=database\n";
    }

    /** Every way at once: Redis in cache and logger, syslog, mailer, auth. */
    private function everyServiceEnv(string $dbPath): string
    {
        return $this->env($dbPath, 'db,redis')
            . "LOG_HANDLERS=redis:ERROR,syslog:ERROR\n"
            . "MAIL_HOST=smtp.unreachable.invalid\nMAIL_PORT=587\nMAIL_FROM_ADDRESS=noreply@example.invalid\n"
            . 'AUTH_TOKEN_KEY=' . base64_encode(str_repeat('k', 32)) . "\n";
    }

    private function createEventTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE domain_events (id INTEGER PRIMARY KEY AUTOINCREMENT, topic VARCHAR(255) NOT NULL, '
            . 'payload TEXT NOT NULL, created_at TEXT NOT NULL, processed_at TEXT NULL DEFAULT NULL, '
            . 'attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT NULL DEFAULT NULL)'
        );
    }

    public function testKernelBuildsWithoutOpeningFileOrRedisAndFirstAccessOpensEachService(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(sys_get_temp_dir(), $this->env($this->dbFile));

        // Built without exception (unreachable Redis is not touched), no file.
        $pool = $kernel->dbConnection();
        self::assertInstanceOf(ConnectionPoolInterface::class, $pool);
        self::assertFileDoesNotExist($this->dbFile);
        self::assertFalse($pool->getReaders()[0]->isConnected());

        // Holding the services opens nothing either.
        $cache = $kernel->cache();
        $messaging = $kernel->messaging();
        self::assertNotNull($cache);
        self::assertNotNull($messaging);
        self::assertFileDoesNotExist($this->dbFile);
        self::assertFalse($pool->getReaders()[0]->isConnected());

        // The project runs its migrations out of band; that is the only thing
        // that creates the file before the first service access.
        $migration = new PDO('sqlite:' . $this->dbFile);
        $migration->exec(
            'CREATE TABLE cache (cache_key TEXT PRIMARY KEY, cache_value TEXT NOT NULL, expires_at INTEGER)'
        );
        $this->createEventTable($migration);
        self::assertFalse($pool->getReaders()[0]->isConnected(), 'the migration PDO is not the kernel connection');

        // First cache access opens the kernel's connection.
        self::assertTrue($cache->set('k', 'v'));
        self::assertTrue($pool->getReaders()[0]->isConnected());
        self::assertSame('v', $cache->get('k'));
    }

    public function testFirstMessagingAccessOpensTheConnection(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(sys_get_temp_dir(), $this->env($this->dbFile));
        $pool = $kernel->dbConnection();
        self::assertInstanceOf(ConnectionPoolInterface::class, $pool);
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);
        self::assertFalse($pool->getReaders()[0]->isConnected());

        $migration = new PDO('sqlite:' . $this->dbFile);
        $this->createEventTable($migration);

        self::assertTrue($messaging->publish('lazy.topic', 'hello'));
        self::assertTrue($pool->getReaders()[0]->isConnected());

        $count = $migration->query('SELECT COUNT(*) FROM domain_events');
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testUnopenableDatabasePathBuildsKernelAndEachServiceThrowsOnFirstAccess(): void
    {
        $missing = '/no/such/directory/at/all/db.sqlite';

        // Builds: the path is not touched, nor is the unreachable Redis.
        $kernel = (new BuildDomainKernelFromEnv())(sys_get_temp_dir(), $this->env($missing));
        $cache = $kernel->cache();
        $messaging = $kernel->messaging();
        self::assertNotNull($cache);
        self::assertNotNull($messaging);

        // Bescheid Rolf 2026-10-15: the error appears at the first access of each service.
        try {
            $cache->get('k');
            self::fail('cache access must throw for an unopenable database');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        try {
            $messaging->publish('t', 'x');
            self::fail('messaging access must throw for an unopenable database');
        } catch (PublishException $e) {
            self::assertStringContainsString('SQLite database directory is not writable', $e->getMessage());
        }
    }

    public function testMessagingConsumeIsLazyToo(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(
            sys_get_temp_dir(),
            $this->env('/no/such/directory/at/all/db.sqlite'),
        );
        $messaging = $kernel->messaging();
        self::assertNotNull($messaging);

        $this->expectException(ConsumerException::class);
        $this->expectExceptionMessage('SQLite database directory is not writable');

        $messaging->consume('t', new class implements MessageHandlerInterface {
            public function handle(string|array $message, array $metadata): bool
            {
                return true;
            }
        });
    }

    public function testKernelBuildsWithEveryServiceConfiguredAndOpensNothing(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(sys_get_temp_dir(), $this->everyServiceEnv($this->dbFile));

        // Built without exception: unreachable Redis, unreachable SMTP host and
        // the not-yet-existing SQLite file are all untouched.
        $pool = $kernel->dbConnection();
        self::assertInstanceOf(ConnectionPoolInterface::class, $pool);
        self::assertNotNull($kernel->cache());
        self::assertNotNull($kernel->logger());
        self::assertNotNull($kernel->messaging());
        self::assertNotNull($kernel->mailer());
        self::assertNotNull($kernel->auth());

        self::assertFileDoesNotExist($this->dbFile);
        self::assertFileDoesNotExist($this->dbFile . '-wal');
        self::assertFalse($pool->getReaders()[0]->isConnected());
    }

    public function testUnreachableRedisCacheLayerBuildsAndThrowsAtFirstCacheAccess(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(sys_get_temp_dir(), $this->env($this->dbFile, 'db,redis'));
        $cache = $kernel->cache();
        self::assertNotNull($cache);
        self::assertFileDoesNotExist($this->dbFile);

        $migration = new PDO('sqlite:' . $this->dbFile);
        $migration->exec(
            'CREATE TABLE cache (cache_key TEXT PRIMARY KEY, cache_value TEXT NOT NULL, expires_at INTEGER)'
        );

        // db layer answers first (miss); the redis layer is where the first
        // access reaches the unreachable host.
        $this->expectException(InvalidEnvConfigurationException::class);

        $cache->get('k');
    }

    public function testLoggerWithRedisAndSyslogBuildsAndReportsRedisFailureAtFirstWrite(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(
            sys_get_temp_dir(),
            $this->env($this->dbFile) . "LOG_HANDLERS=redis:ERROR,syslog:ERROR\n",
        );
        $logger = $kernel->logger();
        self::assertInstanceOf(Logger::class, $logger);
        self::assertCount(1, $logger->getHandlersByClass(LogRedis::class));
        self::assertCount(1, $logger->getHandlersByClass(LogSyslog::class));

        // The first write must not throw into the caller although Redis is
        // unreachable (the Logger routes handler failures to its error handler),
        // and the failure must not stay invisible: the kernel's error handler
        // reports it via error_log() (O20).
        $reported = $this->captureErrorLog(static fn () => $logger->error('first write'));
        self::assertStringContainsString('[BuildLoggerFromEnv] log handler "', $reported);
        self::assertStringContainsString(
            'Redis connection to host "nonexistent_host_that_does_not_exist" failed',
            $reported,
        );

        // The handler itself did not swallow the provider's exception.
        $this->expectException(InvalidEnvConfigurationException::class);

        $logger->getHandlersByClass(LogRedis::class)[array_key_first($logger->getHandlersByClass(LogRedis::class))]
            ->getRedis();
    }
}
