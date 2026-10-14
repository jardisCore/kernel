<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AK2.5 (PLAN.md §P2) — a configured but unreachable service throws instead
 * of degrading to `null` behind a bare `error_log` (R1.3 rule 3,
 * BEFUNDE.md §1e), for DB and Redis.
 *
 * Uses an unresolvable hostname rather than a real database/Redis service —
 * fails fast (DNS lookup failure), no Docker dependency, same approach the
 * pre-existing `BuildConnectionFromEnvTest`/`BuildRedisFromEnvTest` already
 * used (just asserting the new exception instead of the old silent `null`).
 */
final class UnreachableServiceExceptionTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/ProjectRoot/' . $name;
    }

    public function testConfiguredUnreachableDbHostBuildsKernelAndThrowsOnFirstAccess(): void
    {
        // The database connection is lazy: bootstrap opens no socket, the
        // unreachable host only surfaces when the domain first uses it.
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('UnreachableDbHost'));

        $connection = $kernel->dbConnection();
        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);

        $this->expectException(RuntimeException::class);

        $connection->getWriter();
    }

    public function testUnreachableDbPortBuildsKernelWithoutException(): void
    {
        $kernel = (new BuildDomainKernelFromEnv())(
            sys_get_temp_dir(),
            "DB_HOST=127.0.0.1\nDB_PORT=1\nDB_USER=u\nDB_PASSWORD=p\nDB_DATABASE=d\n",
        );

        $connection = $kernel->dbConnection();
        self::assertInstanceOf(ConnectionPoolInterface::class, $connection);

        $this->expectException(RuntimeException::class);

        $connection->getWriter();
    }

    public function testConfiguredUnreachableRedisHostThrows(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildDomainKernelFromEnv())($this->fixturePath('UnreachableRedisHost'));
    }
}
