<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
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
 * Worst case on purpose: SQLite file that does not exist yet, an unreachable
 * Redis host, and the two consumers that need the writer PDO up front
 * (`CACHE_LAYERS=db`, `MESSAGING_TRANSPORT=database`).
 */
final class LazyBootstrapInvariantTest extends TestCase
{
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

    private function env(string $dbPath): string
    {
        return "DB_DRIVER=sqlite\nDB_PATH={$dbPath}\n"
            . "REDIS_HOST=nonexistent_host_that_does_not_exist\nREDIS_PORT=6379\n"
            . "CACHE_LAYERS=db\nCACHE_DB_TABLE=cache\n"
            . "MESSAGING_TRANSPORT=database\n";
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
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
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

        $this->expectException(RuntimeException::class);

        $messaging->consume('t', new class implements MessageHandlerInterface {
            public function handle(string|array $message, array $metadata): bool
            {
                return true;
            }
        });
    }
}
