<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;

/**
 * Builds a database connection from ENV values.
 *
 * Supports mysql, pgsql, sqlite — always as a `ConnectionPool` from
 * jardisadapter/dbconnection (`^1.4`). The pool holds a single lazy writer
 * (plus read replicas when DB_READER*_HOST is set): NO connection is opened
 * here — the writer/readers open on first access, so a request without
 * database access opens no connection, and an unreachable host (or an
 * unopenable sqlite path, which is validated and gets its PRAGMAs at the first
 * open) surfaces at first access, not at bootstrap. Optional pool tuning via
 * DB_POOL_* keys ({@see BuildConnectionPoolConfigFromEnv}); without any of
 * them the pool is built with the adapter defaults.
 *
 * There is NO plain-PDO fallback (Entscheid O23 2026-10-16, Bescheid Rolf
 * 2026-10-15: all services lazy — an eager `new PDO` would be the one
 * remaining eager path). A configured database without the adapter is a
 * configuration error: {@see InvalidEnvConfigurationException} at build time,
 * decided purely from the ENV and `class_exists()` — no I/O, no file, no
 * socket.
 *
 * Presence checks (`db_host`, `db_reader{n}_host`) go through
 * {@see IsEnvValueUnset}: DotEnv's cast chain reads an explicitly empty
 * value (`DB_HOST=`) as the literal `bool(false)`, not `''` — a plain
 * `=== null` check would misread it as configured.
 *
 * G5 error rules (R1.3): no `db_driver`/`db_host` at all means the database
 * is not configured — `null` (unchanged). "Configured" is `db_driver=sqlite`
 * or a set `db_host`. Once configured, an invalid `DB_POOL_*` value throws
 * {@see InvalidEnvConfigurationException} (rethrown by `buildPool` as is), and
 * every other error while assembling the pool is passed on to the caller —
 * nothing is swallowed into a degraded connection.
 */
final class BuildConnectionFromEnv
{
    /** @param (Closure(): bool)|null $adapterAvailable replaces the `class_exists(ConnectionPool::class)` check (test seam) */
    public function __construct(private readonly ?Closure $adapterAvailable = null)
    {
    }

    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env): ?ConnectionPoolInterface
    {
        $driver = (string) ($env('db_driver') ?? 'mysql');

        if ($driver === 'sqlite') {
            $this->assertAdapterInstalled();

            return $this->buildSqlite($env);
        }

        if ((new IsEnvValueUnset())($env('db_host'))) {
            return null;
        }

        $this->assertAdapterInstalled();

        return $this->buildPool($env, $driver, $this->findReaders($env));
    }

    private function assertAdapterInstalled(): void
    {
        $installed = $this->adapterAvailable !== null
            ? ($this->adapterAvailable)()
            : class_exists(ConnectionPool::class);

        if (!$installed) {
            throw new InvalidEnvConfigurationException(
                'A database is configured (DB_DRIVER/DB_HOST) but jardisadapter/dbconnection (^1.4) is not '
                . 'installed — require it to use the database; there is no plain-PDO fallback.'
            );
        }
    }

    /** @param Closure(string): mixed $env */
    private function buildSqlite(Closure $env): ConnectionPoolInterface
    {
        $path = (string) ($env('db_path') ?? ':memory:');

        // Bescheid Rolf 2026-10-15: all services lazy. The sqlite writer
        // opens on first pdo() (dbconnection >= 1.4), like mysql/pgsql.
        return new ConnectionPool((new ConnectionFactory())->sqlite($path));
    }

    /**
     * @param Closure(string): mixed $env
     * @param array<int, array{host: string, port: ?int, user: ?string, password: ?string, database: ?string}> $readers
     */
    private function buildPool(Closure $env, string $driver, array $readers): ConnectionPoolInterface
    {
        $host = (string) $env('db_host');
        $port = (int) ($env('db_port') ?? ($driver === 'pgsql' ? 5432 : 3306));
        $user = (string) ($env('db_user') ?? 'root');
        $password = (string) ($env('db_password') ?? '');
        $database = (string) ($env('db_database') ?? '');
        $charset = (string) ($env('db_charset') ?? ($driver === 'pgsql' ? 'utf8' : 'utf8mb4'));

        // Built first: an invalid DB_POOL_* value fails fast with an
        // InvalidEnvConfigurationException (from BuildConnectionPoolConfigFromEnv),
        // before any connection object is created. Entscheid O23 2026-10-16: no
        // catch-\Throwable fallback — errors while assembling the pool are
        // passed on, not degraded into an eager PDO.
        $config = (new BuildConnectionPoolConfigFromEnv())($env);

        $factory = new ConnectionFactory();

        $writerConn = $driver === 'pgsql'
            ? $factory->postgres($host, $user, $password, $database, $port)
            : $factory->mysql($host, $user, $password, $database, $port, $charset);

        $readerConns = [];
        foreach ($readers as $reader) {
            $rHost = $reader['host'];
            $rPort = $reader['port'] ?? $port;
            $rUser = $reader['user'] ?? $user;
            $rPass = $reader['password'] ?? $password;
            $rDb = $reader['database'] ?? $database;

            $readerConns[] = $driver === 'pgsql'
                ? $factory->postgres($rHost, $rUser, $rPass, $rDb, $rPort)
                : $factory->mysql($rHost, $rUser, $rPass, $rDb, $rPort, $charset);
        }

        return $config === null
            ? new ConnectionPool($writerConn, $readerConns)
            : new ConnectionPool($writerConn, $readerConns, $config);
    }

    /**
     * @param Closure(string): mixed $env
     * @return array<int, array{host: string, port: ?int, user: ?string, password: ?string, database: ?string}>
     */
    private function findReaders(Closure $env): array
    {
        $readers = [];

        for ($i = 1;; $i++) {
            $host = $env("db_reader{$i}_host");
            if ((new IsEnvValueUnset())($host)) {
                break;
            }

            $portVal = $env("db_reader{$i}_port");
            $userVal = $env("db_reader{$i}_user");
            $passVal = $env("db_reader{$i}_password");
            $dbVal = $env("db_reader{$i}_database");

            $readers[] = [
                'host' => (string) $host,
                'port' => $portVal !== null ? (int) $portVal : null,
                'user' => $userVal !== null ? (string) $userVal : null,
                'password' => $passVal !== null ? (string) $passVal : null,
                'database' => $dbVal !== null ? (string) $dbVal : null,
            ];
        }

        return $readers;
    }
}
