<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use Redis;
use RedisException;

/**
 * Builds the Redis provider from ENV values.
 *
 * Shared between the cache and logger builders (Redis fan-out, D4): one
 * provider feeds both `BuildCacheFromEnv` and `BuildLoggerFromEnv`, and
 * `jardisadapter/cache` / `jardisadapter/logger` (>= 1.1) accept it as
 * `Closure(): Redis` and call it at their own first use.
 *
 * Bescheid Rolf 2026-10-15: all services lazy. This handler checks the
 * configuration eagerly without any I/O — `{prefix}host` set and `ext-redis`
 * installed, otherwise `null` (a missing-optional-adapter / not-configured
 * case, like every other Handler's `class_exists()` guard; without the guard
 * `new Redis()` would raise an `\Error`). `connect`/`auth`/`select` run inside
 * the returned provider, so nothing opens while the kernel is built.
 *
 * A configured but unreachable host is a different case (G5 rule 3): the
 * provider throws {@see InvalidEnvConfigurationException} at the first
 * consumer access instead of silently degrading to `null`.
 *
 * The provider is memoized on success: the adapters memoize their own result
 * but not across each other, so without it cache and logger would open two
 * connections instead of one (D4 fan-out, {@see MemoizeProvider}).
 *
 * A failure is memoized as well, for the lifetime of this provider (O22):
 * the first attempt's {@see InvalidEnvConfigurationException} is kept and
 * thrown again on every later call, without a new `connect`. Otherwise every
 * cache access and every log line would pay the socket timeout again against
 * an unreachable host (`connect` has no timeout key) — one connect attempt
 * per kernel, as the former bootstrap-time failure was one abort per boot.
 * This lives here and not in the generic {@see MemoizeProvider}, which stays
 * success-only; the adapters' own retry semantics are unchanged.
 */
final class BuildRedisFromEnv
{
    /**
     * @param Closure(string): mixed $env
     * @return (Closure(): Redis)|null
     */
    public function __invoke(Closure $env, string $prefix = 'redis_'): ?Closure
    {
        $host = $env($prefix . 'host');
        if ((new IsEnvValueUnset())($host) || !class_exists(Redis::class)) {
            return null;
        }

        /** @var InvalidEnvConfigurationException|null $failure */
        $failure = null;

        return (new MemoizeProvider())(
            static function () use ($env, $prefix, $host, &$failure): Redis {
                if ($failure !== null) {
                    throw $failure;
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
                    $failure = new InvalidEnvConfigurationException(
                        sprintf('Redis connection to host "%s" failed: %s', (string) $host, $e->getMessage()),
                        previous: $e,
                    );

                    throw $failure;
                }
            }
        );
    }
}
