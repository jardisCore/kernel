<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildConnectionFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for BuildConnectionFromEnv — ported 1:1 from foundation's ConnectionHandler.
 *
 * Network-dependent branches (unreachable mysql/pgsql host, ConnectionPool
 * build failure) use an unresolvable hostname rather than a real database
 * service — fails fast (DNS lookup failure), no Docker dependency (Plan P2 AK).
 *
 * R1 (G5 rule 3): once a driver is configured (db_driver=sqlite or db_host
 * set), a connection failure throws InvalidEnvConfigurationException instead
 * of degrading to null with a bare error_log — see BEFUNDE.md §1e.
 */
final class BuildConnectionFromEnvTest extends TestCase
{
    public function testSqliteInMemoryReturnsPdo(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_driver' => 'sqlite',
            'db_path' => ':memory:',
        ]));

        self::assertInstanceOf(PDO::class, $connection);
        self::assertSame('sqlite', $connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(PDO::ERRMODE_EXCEPTION, $connection->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testSqliteDefaultsToInMemoryWhenPathMissing(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom(['db_driver' => 'sqlite']));

        self::assertInstanceOf(PDO::class, $connection);
    }

    public function testSqliteUnopenableFileThrows(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildConnectionFromEnv())($this->envFrom([
            'db_driver' => 'sqlite',
            'db_path' => '/no/such/directory/at/all/db.sqlite',
        ]));
    }

    public function testNoHostReturnsNullForDefaultMysqlDriver(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([]));

        self::assertNull($connection);
    }

    public function testUnreachableMysqlHostReturnsLazyPoolAndThrowsOnFirstAccess(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => 'nonexistent_host_that_does_not_exist',
        ]));

        // No socket opened at build time: a pool with a single, not yet connected writer.
        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
        // getReaders() without readers yields the writer, without a health check.
        self::assertFalse($connection->getReaders()[0]->isConnected());
    }

    public function testLazyPoolFirstAccessThrowsOnUnreachableHost(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => '127.0.0.1',
            'db_port' => 1,
        ]));

        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);

        $this->expectException(RuntimeException::class);

        $connection->getWriter()->pdo();
    }

    public function testUnreachablePgsqlHostReturnsLazyPool(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_driver' => 'pgsql',
            'db_host' => 'nonexistent_host_that_does_not_exist',
        ]));

        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
        self::assertSame('pgsql', $connection->getReaders()[0]->getDriverName());
    }

    public function testReaderConfigurationReturnsLazyPoolWithoutConnecting(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => 'nonexistent_host_that_does_not_exist',
            'db_reader1_host' => 'nonexistent_reader_host_that_does_not_exist',
        ]));

        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
        self::assertCount(1, $connection->getReaders());
        self::assertFalse($connection->getReaders()[0]->isConnected());
    }

    public function testPoolEnvWithStickyWriterKeyStillBuildsLazily(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => 'nonexistent_host_that_does_not_exist',
            'db_reader1_host' => 'nonexistent_reader_host_that_does_not_exist',
            'db_pool_sticky_writer' => true,
        ]));

        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
    }

    public function testInvalidPoolStrategyEnvIsRethrownBeforeAnyPdoFallback(): void
    {
        // R1 Senior-PHP blocker: the adapter-side InvalidArgumentException
        // from an invalid DB_POOL_LOAD_BALANCING_STRATEGY is wrapped as
        // InvalidEnvConfigurationException and rethrown immediately — it
        // must never disappear into the plain-PDO fallback.
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => 'nonexistent_host_that_does_not_exist',
            'db_reader1_host' => 'nonexistent_reader_host_that_does_not_exist',
            'db_pool_load_balancing_strategy' => 'no-such-strategy',
        ]));
    }

    public function testConnectionPoolInterfaceIsTheDocumentedReturnType(): void
    {
        // Compile-time / static documentation check — the union return type
        // includes ConnectionPoolInterface even though this test suite cannot
        // exercise a successful pool build without a real DB service.
        self::assertTrue(is_subclass_of(
            \JardisAdapter\DbConnection\ConnectionPool::class,
            ConnectionPoolInterface::class,
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return Closure(string): mixed
     */
    private function envFrom(array $data): Closure
    {
        return static fn (string $key): mixed => $data[strtolower($key)] ?? null;
    }
}
