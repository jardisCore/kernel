<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\Logger\Handler\LogRedis;
use JardisAdapter\Logger\Logger;
use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisCore\Kernel\Tests\Support\CapturesErrorLog;
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
    use CapturesErrorLog;

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

    public function testConfiguredUnreachableRedisHostBuildsKernelWhenNoServiceUsesIt(): void
    {
        // Bescheid Rolf 2026-10-15: alle Dienste lazy — a configured but
        // unreachable Redis that no cache layer or log handler uses must not
        // fail the boot (it used to, the client connected at bootstrap).
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('UnreachableRedisHost'));

        self::assertSame($this->fixturePath('UnreachableRedisHost'), $kernel->projectRoot());
    }

    public function testConfiguredUnreachableRedisHostThrowsOnFirstCacheAccess(): void
    {
        // The configured-but-unreachable rule (G5 rule 3) stays loud — it now
        // fires where Redis is first needed, not while the kernel is built.
        $kernel = (new BuildDomainKernelFromEnv())(
            sys_get_temp_dir(),
            "REDIS_HOST=nonexistent_host_that_does_not_exist\nREDIS_PORT=6379\nCACHE_LAYERS=redis\n",
        );

        $cache = $kernel->cache();
        self::assertNotNull($cache);

        $this->expectException(InvalidEnvConfigurationException::class);

        $cache->get('anything');
    }

    public function testUnreachableRedisHostWithRedisLogHandlerBuildsAndFailsAtFirstLogWrite(): void
    {
        // Bescheid Rolf 2026-10-15: alle Dienste lazy. jardisadapter/logger
        // >= 1.1 takes the Redis provider, so a listed `redis` handler no
        // longer connects while the logger is built. The configured-but-
        // unreachable rule stays loud: the handler does not swallow the
        // provider's exception (the Logger routes it to its error handler).
        $kernel = (new BuildDomainKernelFromEnv())(
            sys_get_temp_dir(),
            "REDIS_HOST=nonexistent_host_that_does_not_exist\nREDIS_PORT=6379\nLOG_HANDLERS=redis\n",
        );
        $logger = $kernel->logger();
        self::assertInstanceOf(Logger::class, $logger);

        $reported = $this->captureErrorLog(static fn () => $logger->error('first write'));
        self::assertStringContainsString('[BuildLoggerFromEnv] log handler "', $reported);

        $handlers = $logger->getHandlersByClass(LogRedis::class);
        self::assertCount(1, $handlers);
        $this->expectException(InvalidEnvConfigurationException::class);

        reset($handlers)->getRedis();
    }
}
