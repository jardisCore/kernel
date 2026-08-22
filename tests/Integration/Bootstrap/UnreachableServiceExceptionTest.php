<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use PHPUnit\Framework\TestCase;

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

    public function testConfiguredUnreachableDbHostThrows(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildDomainKernelFromEnv())($this->fixturePath('UnreachableDbHost'));
    }

    public function testConfiguredUnreachableRedisHostThrows(): void
    {
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildDomainKernelFromEnv())($this->fixturePath('UnreachableRedisHost'));
    }
}
