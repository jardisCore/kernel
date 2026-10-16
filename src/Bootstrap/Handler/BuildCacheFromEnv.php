<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\Cache\Adapter\CacheApcu;
use JardisAdapter\Cache\Adapter\CacheDatabase;
use JardisAdapter\Cache\Adapter\CacheMemory;
use JardisAdapter\Cache\Adapter\CacheRedis;
use JardisAdapter\Cache\Cache;
use JardisCore\Kernel\Bootstrap\Data\CacheLayer;
use PDO;
use Psr\SimpleCache\CacheInterface;
use Redis;

/**
 * Builds a PSR-16 cache from ENV values.
 *
 * Requires jardisadapter/cache. Layer order defined by CACHE_LAYERS (comma-separated).
 * Example: CACHE_LAYERS=memory,redis,db
 *
 * The Redis connection is part of the Bootstrap-Packer's
 * Redis fan-out (D4) — built once by `BuildRedisFromEnv` and shared with
 * `BuildLoggerFromEnv`.
 *
 * Bescheid Rolf 2026-10-15: all services lazy. `$pdo` / `$redis` may be a
 * provider Closure ({@see ProvideWriterPdo}, {@see BuildRedisFromEnv}); it is
 * handed to `CacheDatabase` / `CacheRedis` (jardisadapter/cache >= 1.1), which
 * call it — and so open the database / Redis — at their first cache access,
 * never while the kernel is built. A ready PDO / Redis object stays valid.
 */
final class BuildCacheFromEnv
{
    /** @param Closure(string): mixed $env */
    public function __invoke(
        Closure $env,
        PDO|Closure|null $pdo = null,
        Redis|Closure|null $redis = null,
    ): ?CacheInterface {
        if (!class_exists(Cache::class)) {
            // @codeCoverageIgnoreStart
            // jardisadapter/cache is a require-dev dependency of this very test
            // suite, so this branch (adapter not installed) is structurally
            // unreachable here — documented gap, not a real path in this repo's QA.
            return null;
            // @codeCoverageIgnoreEnd
        }

        $namespace = $env('cache_namespace') !== null
            ? (string) $env('cache_namespace')
            : null;

        $layerNames = $env('cache_layers') !== null
            ? array_map('trim', explode(',', (string) $env('cache_layers')))
            : [];

        $layers = [];

        foreach ($layerNames as $name) {
            $type = CacheLayer::tryFrom($name);
            if ($type === null) {
                continue;
            }

            try {
                $layer = match ($type) {
                    CacheLayer::Memory => new CacheMemory($namespace),
                    CacheLayer::Apcu => new CacheApcu($namespace),
                    CacheLayer::Redis => $redis === null ? null : new CacheRedis($redis, $namespace),
                    CacheLayer::Database => $pdo === null ? null : new CacheDatabase(
                        $pdo,
                        (string) ($env('cache_db_table') ?? 'cache'),
                        namespace: $namespace,
                    ),
                };
            } catch (\Throwable) {
                // @codeCoverageIgnoreStart
                // Defensive safety net: none of the four adapter constructors
                // (CacheMemory/CacheApcu/CacheRedis/CacheDatabase) validate
                // eagerly in the installed adapter version, so this branch
                // cannot be forced without a broken/incompatible adapter build.
                continue;
                // @codeCoverageIgnoreEnd
            }

            if ($layer !== null) {
                $layers[] = $layer;
            }
        }

        return new Cache($layers);
    }
}
