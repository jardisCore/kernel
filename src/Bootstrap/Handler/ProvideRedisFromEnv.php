<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use Redis;

/**
 * Lazy access to the Redis client the packer fans out to cache and logger.
 *
 * {@see BuildRedisFromEnv} connects immediately, so calling it at bootstrap
 * made an unreachable but unused Redis fail the whole boot. The provider runs
 * that builder at the consumer's first use instead (and only once). It is
 * `null` exactly where the builder would return `null` for lack of
 * configuration (no `REDIS_HOST`, no ext-redis) — "not configured" stays
 * distinct from "configured but unreachable", which now throws
 * {@see \JardisCore\Kernel\Exception\InvalidEnvConfigurationException} at that
 * first use.
 */
final class ProvideRedisFromEnv
{
    /**
     * @param Closure(string): mixed $env
     * @return (Closure(): Redis)|null
     */
    public function __invoke(Closure $env, string $prefix = 'redis_'): ?Closure
    {
        if ((new IsEnvValueUnset())($env($prefix . 'host')) || !class_exists(Redis::class)) {
            return null;
        }

        return (new MemoizeProvider())(
            static fn (): Redis => (new BuildRedisFromEnv())($env, $prefix)
                ?? throw new \LogicException('A configured Redis host always yields a client.')
        );
    }
}
