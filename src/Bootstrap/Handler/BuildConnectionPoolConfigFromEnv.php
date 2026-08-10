<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;

/**
 * Builds an optional ConnectionPoolConfig from ENV values.
 *
 * Reads the five DB_POOL_* keys. Returns null when none of them is set, so
 * {@see BuildConnectionFromEnv} keeps constructing the ConnectionPool without
 * an explicit config — behaviour unchanged for existing setups. Keys that are
 * set are passed as named arguments; unset keys keep the ConnectionPoolConfig
 * defaults (defined by jardisadapter/dbconnection, not duplicated here).
 *
 * Booleans follow the kernel-wide string comparison (`BuildHttpClientFromEnv`):
 * only the literal `true` is true.
 *
 * `DB_POOL_STICKY_WRITER` requires jardisadapter/dbconnection >= 1.1.0
 * (`stickyWriterDuringTransaction`); on older versions the resulting unknown
 * named argument throws and BuildConnectionFromEnv's existing \Throwable
 * fallback to plain PDO applies.
 */
final class BuildConnectionPoolConfigFromEnv
{
    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env): ?ConnectionPoolConfig
    {
        if (!class_exists(ConnectionPoolConfig::class)) {
            // @codeCoverageIgnoreStart
            // jardisadapter/dbconnection is a require-dev dependency of this
            // very test suite, so this branch (adapter not installed) is
            // structurally unreachable here — documented gap, not a real path
            // in this repo's QA.
            return null;
            // @codeCoverageIgnoreEnd
        }

        $arguments = [];

        $validate = $env('db_pool_validate_connections');
        if ($validate !== null) {
            $arguments['validateConnections'] = $validate === 'true';
        }

        $cacheTtl = $env('db_pool_health_check_cache_ttl');
        if ($cacheTtl !== null) {
            $arguments['healthCheckCacheTtl'] = (int) $cacheTtl;
        }

        $negativeCacheTtl = $env('db_pool_health_check_negative_cache_ttl');
        if ($negativeCacheTtl !== null) {
            $arguments['healthCheckNegativeCacheTtl'] = (int) $negativeCacheTtl;
        }

        $strategy = $env('db_pool_load_balancing_strategy');
        if ($strategy !== null) {
            $arguments['loadBalancingStrategy'] = (string) $strategy;
        }

        $sticky = $env('db_pool_sticky_writer');
        if ($sticky !== null) {
            $arguments['stickyWriterDuringTransaction'] = $sticky === 'true';
        }

        if ($arguments === []) {
            return null;
        }

        return new ConnectionPoolConfig(...$arguments);
    }
}
