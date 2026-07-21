<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\BuildCacheFromEnv;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * Tests for BuildCacheFromEnv — ported 1:1 from foundation's CacheHandler.
 */
final class BuildCacheFromEnvTest extends TestCase
{
    public function testNoLayersReturnsUsableNoOpCache(): void
    {
        $cache = (new BuildCacheFromEnv())($this->envFrom([]));

        self::assertInstanceOf(CacheInterface::class, $cache);
        // No layers configured -> internal no-op fallback, get() returns the default.
        self::assertSame('fallback', $cache->get('missing_key', 'fallback'));
    }

    public function testMemoryLayerRoundTrips(): void
    {
        $cache = (new BuildCacheFromEnv())($this->envFrom(['cache_layers' => 'memory']));

        self::assertInstanceOf(CacheInterface::class, $cache);
        $cache->set('key', 'value');
        self::assertSame('value', $cache->get('key'));
    }

    public function testUnknownLayerNameIsSkippedWithoutError(): void
    {
        $cache = (new BuildCacheFromEnv())($this->envFrom(['cache_layers' => 'bogus,memory']));

        self::assertInstanceOf(CacheInterface::class, $cache);
        $cache->set('key', 'value');
        self::assertSame('value', $cache->get('key'));
    }

    public function testRedisLayerIsSkippedWhenNoRedisConnectionProvided(): void
    {
        $cache = (new BuildCacheFromEnv())($this->envFrom(['cache_layers' => 'redis']), null, null);

        self::assertInstanceOf(CacheInterface::class, $cache);
        self::assertNull($cache->get('anything'));
    }

    public function testDatabaseLayerIsSkippedWhenNoPdoProvided(): void
    {
        $cache = (new BuildCacheFromEnv())($this->envFrom(['cache_layers' => 'db']), null, null);

        self::assertInstanceOf(CacheInterface::class, $cache);
    }

    public function testDatabaseLayerBuildsWhenPdoProvided(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE cache (cache_key TEXT PRIMARY KEY, cache_value TEXT NOT NULL, expires_at INTEGER)',
        );

        $cache = (new BuildCacheFromEnv())($this->envFrom(['cache_layers' => 'db']), $pdo, null);

        self::assertInstanceOf(CacheInterface::class, $cache);
        $cache->set('db_key', 'db_value');
        self::assertSame('db_value', $cache->get('db_key'));
    }

    public function testCustomNamespaceIsAccepted(): void
    {
        $cache = (new BuildCacheFromEnv())($this->envFrom([
            'cache_layers' => 'memory',
            'cache_namespace' => 'custom',
        ]));

        self::assertInstanceOf(CacheInterface::class, $cache);
        $cache->set('key', 'namespaced');
        self::assertSame('namespaced', $cache->get('key'));
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
