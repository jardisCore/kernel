<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildConnectionFromEnv;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BuildConnectionFromEnv — ported 1:1 from foundation's ConnectionHandler.
 *
 * Network-dependent branches (unreachable mysql/pgsql host, ConnectionPool
 * build failure) use an unresolvable hostname rather than a real database
 * service — fails fast (DNS lookup failure), no Docker dependency (Plan P2 AK).
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

    public function testSqliteUnopenableFileReturnsNull(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_driver' => 'sqlite',
            'db_path' => '/no/such/directory/at/all/db.sqlite',
        ]));

        self::assertNull($connection);
    }

    public function testNoHostReturnsNullForDefaultMysqlDriver(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([]));

        self::assertNull($connection);
    }

    public function testUnreachableMysqlHostReturnsNull(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => 'nonexistent_host_that_does_not_exist',
        ]));

        self::assertNull($connection);
    }

    public function testUnreachablePgsqlHostReturnsNull(): void
    {
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_driver' => 'pgsql',
            'db_host' => 'nonexistent_host_that_does_not_exist',
        ]));

        self::assertNull($connection);
    }

    public function testReaderConfigurationWithUnreachableHostsFallsBackToNull(): void
    {
        // db_reader1_host present -> findReaders() detects a reader -> buildPool()
        // is attempted (ConnectionPool is installed via require-dev); both writer
        // and reader are unreachable, so the pool build fails and the internal
        // catch falls back to buildPdo(), which also fails -> null overall.
        $connection = (new BuildConnectionFromEnv())($this->envFrom([
            'db_host' => 'nonexistent_host_that_does_not_exist',
            'db_reader1_host' => 'nonexistent_reader_host_that_does_not_exist',
        ]));

        self::assertNull($connection);
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
