<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use InvalidArgumentException;
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;

/**
 * Builds an optional ConnectionPoolConfig from ENV values.
 *
 * Reads the five DB_POOL_* keys. Returns null when none of them is set, so
 * {@see BuildConnectionFromEnv} keeps constructing the ConnectionPool without
 * an explicit config — behaviour unchanged for existing setups. Keys that are
 * set are passed as named arguments; unset keys keep the ConnectionPoolConfig
 * defaults (defined by jardisadapter/dbconnection, not duplicated here).
 *
 * Booleans go through {@see NormalizeEnvBool} (R1 fix) — the prior
 * `=== 'true'` Roh-String comparison silently misread the DotEnv-cast
 * `bool`/`int` values.
 *
 * An invalid `DB_POOL_LOAD_BALANCING_STRATEGY` is the adapter's own
 * `InvalidArgumentException` (eager validation in `ConnectionPoolConfig`'s
 * constructor) — wrapped here as {@see InvalidEnvConfigurationException} so
 * {@see BuildConnectionFromEnv} can recognize and rethrow it instead of
 * swallowing it into the plain-PDO fallback (G5 rule 2).
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

        $validate = (new NormalizeEnvBool())($env('db_pool_validate_connections'), 'DB_POOL_VALIDATE_CONNECTIONS');
        if ($validate !== null) {
            $arguments['validateConnections'] = $validate;
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

        $sticky = (new NormalizeEnvBool())($env('db_pool_sticky_writer'), 'DB_POOL_STICKY_WRITER');
        if ($sticky !== null) {
            $arguments['stickyWriterDuringTransaction'] = $sticky;
        }

        if ($arguments === []) {
            return null;
        }

        try {
            return new ConnectionPoolConfig(...$arguments);
        } catch (InvalidArgumentException $e) {
            throw new InvalidEnvConfigurationException($e->getMessage(), previous: $e);
        }
    }
}
