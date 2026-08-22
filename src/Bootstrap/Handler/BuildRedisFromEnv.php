<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use Redis;
use RedisException;

/**
 * Builds a Redis connection from ENV values.
 *
 * Shared between the cache and logger builders (Redis fan-out, D4): one
 * connection feeds both `BuildCacheFromEnv` and `BuildLoggerFromEnv`.
 *
 * `ext-redis` not being installed is a missing-optional-adapter case (like
 * every other Handler's `class_exists()` guard) — degrades to `null`.
 * Without the guard, `new Redis()` would raise an `\Error` the
 * `RedisException` catch below cannot intercept.
 *
 * A configured but unreachable host is a different case (G5 rule 3): once
 * `{prefix}host` is set, a failed `connect()`/`auth()`/`select()` throws
 * {@see InvalidEnvConfigurationException} instead of silently degrading to
 * `null` — the prior behaviour hid a broken, explicitly configured Redis
 * behind a working-looking `null` cache/logger.
 *
 * Ported 1:1 from `jardiscore/foundation` (`Handler\RedisHandler`,
 * Kernel-Entkopplung P2).
 */
final class BuildRedisFromEnv
{
    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env, string $prefix = 'redis_'): ?Redis
    {
        $host = $env($prefix . 'host');
        if ((new IsEnvValueUnset())($host) || !class_exists(Redis::class)) {
            return null;
        }

        try {
            $redis = new Redis();
            $redis->connect(
                (string) $host,
                (int) ($env($prefix . 'port') ?? 6379),
            );

            $password = $env($prefix . 'password');
            if ($password !== null && $password !== '') {
                $redis->auth((string) $password);
            }

            $database = $env($prefix . 'database');
            if ($database !== null) {
                $redis->select((int) $database);
            }

            return $redis;
        } catch (RedisException $e) {
            throw new InvalidEnvConfigurationException(
                sprintf('Redis connection to host "%s" failed: %s', (string) $host, $e->getMessage()),
                previous: $e,
            );
        }
    }
}
