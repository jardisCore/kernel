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
 *
 * Added beyond that subset: the missing-`ext-redis` branch, which needs no
 * Redis service either — it forks a PHP without extensions instead (see the
 * test's own docblock).
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
     * `ext-redis` is optional (composer `suggest`), so a configured host with
     * no extension installed is a reachable state — and `new Redis()` then
     * raises an `\Error`, which the handler's `RedisException` catch cannot
     * intercept. The packer's contract says otherwise: "every Handler closure
     * degrades to null via class_exists() guards"
     * (`Bootstrap\BuildDomainKernelFromEnv` class docblock).
     *
     * The extension cannot be unloaded inside a running process, so this test
     * forks a second PHP without any extensions (`php -n`) — there `Redis`
     * genuinely does not exist. Without the guard the fork dies with
     * `Error: Class "Redis" not found`; with it, the closure returns null.
     */
    public function testConfiguredHostWithoutRedisExtensionDegradesToNull(): void
    {
        self::assertTrue(
            class_exists('Redis'),
            'Premise of this test: the test image ships ext-redis, so the parent '
            . 'process reaches `new Redis()` and the fork below is the only place '
            . 'where the extension is absent. If this fails, the image changed and '
            . 'this test no longer proves anything.'
        );

        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        self::assertFileExists(
            $autoload,
            'Autoloader not found — this test resolves it relative to its own location, '
            . 'so moving the file needs the dirname() depth adjusted.'
        );

        $code = sprintf(
            'require %s; $env = static fn (string $k): mixed'
            . ' => ["redis_host" => "localhost"][strtolower($k)] ?? null;'
            . ' var_export((new \JardisCore\Kernel\Bootstrap\Handler\BuildRedisFromEnv())($env));',
            var_export($autoload, true)
        );

        $output = [];
        $exitCode = 1;
        exec(
            escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($code) . ' 2>&1',
            $output,
            $exitCode
        );
        $stdout = implode("\n", $output);

        self::assertSame(0, $exitCode, 'Fork without ext-redis must not fatal. Output: ' . $stdout);
        self::assertStringContainsString('NULL', $stdout);
        self::assertStringNotContainsString('Class "Redis" not found', $stdout);
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
