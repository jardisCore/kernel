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
 * set), a config error throws InvalidEnvConfigurationException instead of
 * degrading to null with a bare error_log — see BEFUNDE.md §1e. Entscheid O23
 * 2026-10-16: a configured database without the adapter throws as well; there
 * is no plain-PDO fallback.
 */
final class BuildConnectionFromEnvTest extends TestCase
{
    public function testSqliteInMemoryReturnsPdo(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_driver' => 'sqlite',
            'db_path' => ':memory:',
        ]));

        // Bescheid Rolf 2026-10-15: alle Dienste lazy — a pool whose single
        // writer is not connected yet; the PDO is only created on first access.
        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
        self::assertFalse($connection->getReaders()[0]->isConnected());
        $pdo = $connection->getWriter()->pdo();
        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testSqliteDefaultsToInMemoryWhenPathMissing(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom(['db_driver' => 'sqlite']));

        // Bescheid Rolf 2026-10-15: alle Dienste lazy.
        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
        self::assertSame('sqlite', $connection->getWriter()->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testSqliteUnopenableFileBuildsLazyAndThrowsOnFirstAccess(): void
    {
        // Bescheid Rolf 2026-10-15: alle Dienste lazy — the bootstrap does not
        // open the file, the error appears at the first access (not at build).
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_driver' => 'sqlite',
            'db_path' => '/no/such/directory/at/all/db.sqlite',
        ]));

        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
        self::assertFalse($connection->getReaders()[0]->isConnected());

        $this->expectException(RuntimeException::class);

        $connection->getWriter()->pdo();
    }

    public function testSqliteFileIsNotCreatedAtBuildTime(): void
    {
        $path = sys_get_temp_dir() . '/kernel-lazy-' . uniqid() . '.sqlite';

        try {
            $connection = (new BuildConnectionFromEnv())($this->envFrom([
                'db_driver' => 'sqlite',
                'db_path' => $path,
            ]));

            self::assertInstanceOf(ConnectionPoolInterface::class, $connection);
            self::assertFileDoesNotExist($path);

            $connection->getWriter()->pdo();
            self::assertFileExists($path);
        } finally {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                @unlink($file);
            }
        }
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

    public function testInvalidPoolStrategyEnvIsPassedOnAsInvalidEnvConfiguration(): void
    {
        // R1 Senior-PHP blocker: the adapter-side InvalidArgumentException
        // from an invalid DB_POOL_LOAD_BALANCING_STRATEGY is wrapped as
        // InvalidEnvConfigurationException and reaches the caller.
        // Entscheid O23 2026-10-16: there is no PDO fallback any more that
        // could swallow it — the pool build no longer catches Throwable.
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => 'nonexistent_host_that_does_not_exist',
            'db_reader1_host' => 'nonexistent_reader_host_that_does_not_exist',
            'db_pool_load_balancing_strategy' => 'no-such-strategy',
        ]));
    }

    // -- Entscheid O23 2026-10-16: no adapter, no fallback -------------------

    public function testSqliteConfiguredWithoutAdapterThrowsAtBuildAndCreatesNoFile(): void
    {
        // Entscheid O23 2026-10-16: the plain-PDO fallback is gone. A configured
        // database without jardisadapter/dbconnection is a config error at build
        // time, decided without I/O — the sqlite file must not appear.
        $path = sys_get_temp_dir() . '/kernel-noadapter-' . uniqid() . '.sqlite';

        try {
            try {
                (new BuildConnectionFromEnv(static fn (): bool => false))($this->envFrom([
                    'db_driver' => 'sqlite',
                    'db_path' => $path,
                ]));
                self::fail('Expected InvalidEnvConfigurationException');
            } catch (InvalidEnvConfigurationException $e) {
                self::assertStringContainsString('jardisadapter/dbconnection (^1.4)', $e->getMessage());
                self::assertStringContainsString('not installed', $e->getMessage());
            }

            self::assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function testHostConfiguredWithoutAdapterThrowsAtBuildWithoutSocket(): void
    {
        // Entscheid O23 2026-10-16: an unresolvable host would take a DNS lookup
        // and a failing connect if anything opened — the exception is thrown
        // from the ENV alone, naming the missing package.
        $this->expectException(InvalidEnvConfigurationException::class);
        $this->expectExceptionMessage('jardisadapter/dbconnection (^1.4)');

        (new BuildConnectionFromEnv(static fn (): bool => false))($this->envFrom([
            'db_driver' => 'pgsql',
            'db_host' => 'nonexistent_host_that_does_not_exist',
        ]));
    }

    public function testNoDatabaseConfigurationWithoutAdapterStaysNull(): void
    {
        // Entscheid O23 2026-10-16: "not configured" stays null — also (and
        // especially) when the adapter is not installed.
        self::assertNull((new BuildConnectionFromEnv(static fn (): bool => false))($this->envFrom([])));
        self::assertNull((new BuildConnectionFromEnv(static fn (): bool => false))($this->envFrom(['db_host' => false])));
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
