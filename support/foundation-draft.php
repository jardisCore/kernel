<?php

/**
 * DRAFT: Vereinfachte Cache- und Connection-Logik für Foundation.
 * Aus dem Kernel-Refactoring entstanden — hierhin übertragen.
 */

// --- CONNECTION (Domain::connection) ---

use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;

/**
 * @param array<string, mixed> $env
 */
function connection(array $env): ?ConnectionPoolInterface
{
    $host = $env['db_writer_host'] ?? null;
    if ($host === null) {
        return null;
    }

    $factory = new ConnectionFactory();
    $writer = createConnection($factory, $env, 'db_writer');

    $readers = [];
    for ($i = 1; isset($env["db_reader{$i}_host"]); $i++) {
        $readers[] = createConnection($factory, $env, "db_reader{$i}", 'db_writer');
    }

    return new ConnectionPool(writer: $writer, readers: $readers);
}

/**
 * @param array<string, mixed> $env
 */
function createConnection(
    ConnectionFactory $factory,
    array $env,
    string $prefix,
    ?string $fallbackPrefix = null,
): \JardisAdapter\DbConnection\Contract\DbConnectionInterface {
    $get = static fn(string $key, string $default = '') =>
        (string) ($env["{$prefix}_{$key}"] ?? ($fallbackPrefix ? ($env["{$fallbackPrefix}_{$key}"] ?? $default) : $default));

    $driver = $get('driver', 'mysql');

    return match ($driver) {
        'pgsql', 'postgres' => $factory->postgres(
            host: $get('host'),
            user: $get('user'),
            password: $get('password'),
            database: $get('name'),
            port: (int) ($get('port') ?: '5432'),
        ),
        'sqlite' => $factory->sqlite(
            path: $get('path', ':memory:'),
        ),
        default => $factory->mysql(
            host: $get('host'),
            user: $get('user'),
            password: $get('password'),
            database: $get('name'),
            port: (int) ($get('port') ?: '3306'),
        ),
    };
}

// --- CACHE (Domain::cache) ---

use JardisAdapter\Cache\Adapter\CacheApcu;
use JardisAdapter\Cache\Adapter\CacheDatabase;
use JardisAdapter\Cache\Adapter\CacheMemory;
use JardisAdapter\Cache\Adapter\CacheRedis;
use JardisAdapter\Cache\Cache;
use Psr\SimpleCache\CacheInterface;
use Redis;

/**
 * @param array<string, mixed> $env
 */
function cache(array $env): ?CacheInterface
{
    $namespace = (string) ($env['cache_namespace'] ?? 'app');
    $layers = [];

    // Memory (enabled by default)
    if (($env['cache_memory_enabled'] ?? 'true') !== 'false') {
        $layers[] = new CacheMemory($namespace);
    }

    // APCu
    if (($env['cache_apcu_enabled'] ?? false) && extension_loaded('apcu')) {
        $layers[] = new CacheApcu($namespace);
    }

    // Redis
    if ($env['cache_redis_host'] ?? false) {
        $redis = new Redis();
        $redis->connect(
            (string) $env['cache_redis_host'],
            (int) ($env['cache_redis_port'] ?? 6379),
        );
        if ($env['cache_redis_password'] ?? false) {
            $redis->auth((string) $env['cache_redis_password']);
        }
        if ($env['cache_redis_database'] ?? false) {
            $redis->select((int) $env['cache_redis_database']);
        }
        $layers[] = new CacheRedis($redis, $namespace);
    }

    // Database
    if ($env['cache_db_enabled'] ?? false) {
        $table = (string) ($env['cache_db_table'] ?? 'cache');
        // TODO: PDO for cache DB layer
    }

    return $layers !== [] ? new Cache($layers) : null;
}

// --- ENV KEYS ---
// DB:    db_writer_host, db_writer_port, db_writer_driver, db_writer_name, db_writer_user, db_writer_password, db_writer_path (sqlite)
//        db_reader{N}_host, db_reader{N}_port, etc. (N = 1, 2, 3, ...)
// Cache: cache_namespace, cache_memory_enabled, cache_apcu_enabled, cache_redis_host, cache_redis_port,
//        cache_redis_password, cache_redis_database, cache_db_enabled, cache_db_table
