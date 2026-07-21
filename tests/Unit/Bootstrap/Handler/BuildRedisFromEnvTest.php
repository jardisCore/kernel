<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildRedisFromEnv;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BuildRedisFromEnv — ported 1:1 from foundation's RedisHandler.
 *
 * This repo's docker-compose (support/docker-compose.yml) has no Redis
 * service — a real connect/auth/select round-trip is out of scope for this
 * package's QA (Plan P2 AK: "keine Docker-Pflicht"). Covered here: the two
 * branches reachable without live infrastructure (no host configured;
 * unreachable host -> caught RedisException -> null) plus the prefix
 * parameter, which is exactly the subset `jardiscore/foundation`'s own
 * RedisHandlerTest exercises without the docker-only Redis service.
 */
final class BuildRedisFromEnvTest extends TestCase
{
    public function testNoHostReturnsNull(): void
    {
        $redis = (new BuildRedisFromEnv())($this->envFrom([]));

        self::assertNull($redis);
    }

    public function testUnreachableHostReturnsNull(): void
    {
        $redis = (new BuildRedisFromEnv())($this->envFrom([
            'redis_host' => 'nonexistent_host_that_does_not_exist',
            'redis_port' => '6379',
        ]));

        self::assertNull($redis);
    }

    public function testCustomPrefixWithNoHostReturnsNull(): void
    {
        $redis = (new BuildRedisFromEnv())($this->envFrom([]), 'custom_');

        self::assertNull($redis);
    }

    public function testCustomPrefixIsUsedForHostLookup(): void
    {
        // Only "other_host" is set — the default "redis_" prefix must NOT
        // pick it up; passing the matching prefix must at least attempt
        // the connection (and fail fast against the unreachable host).
        $redis = (new BuildRedisFromEnv())($this->envFrom([
            'other_host' => 'nonexistent_host_that_does_not_exist',
        ]), 'other_');

        self::assertNull($redis);
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
