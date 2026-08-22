<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use PDO;
use PDOException;

/**
 * Builds a database connection from ENV values.
 *
 * Supports mysql, pgsql, sqlite. Creates a ConnectionPool with read
 * replicas when DB_READER*_HOST is set and jardisadapter/dbconnection
 * is installed. Falls back to plain PDO otherwise. Optional pool tuning
 * via DB_POOL_* keys ({@see BuildConnectionPoolConfigFromEnv}); without
 * any of them the pool is built exactly as before.
 *
 * Presence checks (`db_host`, `db_reader{n}_host`) go through
 * {@see IsEnvValueUnset}: DotEnv's cast chain reads an explicitly empty
 * value (`DB_HOST=`) as the literal `bool(false)`, not `''` — a plain
 * `=== null` check would misread it as configured.
 *
 * G5 error rules (R1.3): no `db_driver`/`db_host` at all means the database
 * is not configured — `null` (unchanged). Once a driver is configured
 * (`db_driver=sqlite` or `db_host` set), any failure to connect —
 * PDO/adapter connection error, or an invalid `DB_POOL_*` value — throws
 * {@see InvalidEnvConfigurationException} instead of degrading to `null`
 * with a bare `error_log`. The invalid-pool-strategy case is rethrown
 * immediately (see `buildPool`), before the plain-PDO fallback is attempted
 * — a config error must never be swallowed by the fallback (Senior-PHP
 * blocker).
 *
 * Ported 1:1 from `jardiscore/foundation` (`Handler\ConnectionHandler`,
 * Kernel-Entkopplung P2).
 */
final class BuildConnectionFromEnv
{
    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env): ConnectionPoolInterface|PDO|null
    {
        $driver = (string) ($env('db_driver') ?? 'mysql');

        if ($driver === 'sqlite') {
            return $this->buildSqlite($env);
        }

        if ((new IsEnvValueUnset())($env('db_host'))) {
            return null;
        }

        $readers = $this->findReaders($env);

        if (!empty($readers) && class_exists(ConnectionPool::class)) {
            return $this->buildPool($env, $driver, $readers);
        }

        return $this->buildPdo($env, $driver);
    }

    /** @param Closure(string): mixed $env */
    private function buildSqlite(Closure $env): PDO
    {
        $path = (string) ($env('db_path') ?? ':memory:');

        try {
            return new PDO('sqlite:' . $path, options: self::PDO_OPTIONS);
        } catch (PDOException $e) {
            throw new InvalidEnvConfigurationException(
                sprintf('SQLite database at "%s" could not be opened: %s', $path, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /** @param Closure(string): mixed $env */
    private function buildPdo(Closure $env, string $driver): PDO
    {
        $host = (string) $env('db_host');
        $port = (int) ($env('db_port') ?? ($driver === 'pgsql' ? 5432 : 3306));
        $user = (string) ($env('db_user') ?? 'root');
        $password = (string) ($env('db_password') ?? '');
        $database = (string) ($env('db_database') ?? '');
        $charset = (string) ($env('db_charset') ?? ($driver === 'pgsql' ? 'utf8' : 'utf8mb4'));

        $dsn = $driver === 'pgsql'
            ? "pgsql:host=$host;port=$port;dbname=$database;options='--client_encoding=$charset'"
            : "$driver:host=$host;port=$port;dbname=$database;charset=$charset";

        try {
            return new PDO($dsn, $user, $password, self::PDO_OPTIONS);
        } catch (PDOException $e) {
            throw new InvalidEnvConfigurationException(
                sprintf('Database connection to host "%s" failed: %s', $host, $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * @param Closure(string): mixed $env
     * @param array<int, array{host: string, port: ?int, user: ?string, password: ?string, database: ?string}> $readers
     */
    private function buildPool(Closure $env, string $driver, array $readers): ConnectionPoolInterface|PDO
    {
        $host = (string) $env('db_host');
        $port = (int) ($env('db_port') ?? ($driver === 'pgsql' ? 5432 : 3306));
        $user = (string) ($env('db_user') ?? 'root');
        $password = (string) ($env('db_password') ?? '');
        $database = (string) ($env('db_database') ?? '');
        $charset = (string) ($env('db_charset') ?? ($driver === 'pgsql' ? 'utf8' : 'utf8mb4'));

        try {
            // Built first: an invalid DB_POOL_* value fails fast and is
            // rethrown below (InvalidEnvConfigurationException catch),
            // before any connection opens.
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
        } catch (InvalidEnvConfigurationException $e) {
            // An invalid DB_POOL_* value is a config error, not a connectivity
            // problem — it must never disappear into the plain-PDO fallback
            // below (Senior-PHP blocker, G5 rule 2).
            throw $e;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[BuildConnectionFromEnv] ConnectionPool build failed, falling back to plain PDO. Reason: %s',
                $e->getMessage(),
            ));

            return $this->buildPdo($env, $driver);
        }
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
