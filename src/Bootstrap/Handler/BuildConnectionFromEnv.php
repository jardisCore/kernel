<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use PDO;
use PDOException;

/**
 * Builds a database connection from ENV values.
 *
 * Supports mysql, pgsql, sqlite. Creates a ConnectionPool with read
 * replicas when DB_READER*_HOST is set and jardisadapter/dbconnection
 * is installed. Falls back to plain PDO otherwise.
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

        if ($env('db_host') === null) {
            return null;
        }

        $readers = $this->findReaders($env);

        if (!empty($readers) && class_exists(ConnectionPool::class)) {
            return $this->buildPool($env, $driver, $readers);
        }

        return $this->buildPdo($env, $driver);
    }

    /** @param Closure(string): mixed $env */
    private function buildSqlite(Closure $env): ?PDO
    {
        $path = (string) ($env('db_path') ?? ':memory:');

        try {
            return new PDO('sqlite:' . $path, options: self::PDO_OPTIONS);
        } catch (PDOException) {
            return null;
        }
    }

    /** @param Closure(string): mixed $env */
    private function buildPdo(Closure $env, string $driver): ?PDO
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
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * @param Closure(string): mixed $env
     * @param array<int, array{host: string, port: ?int, user: ?string, password: ?string, database: ?string}> $readers
     */
    private function buildPool(Closure $env, string $driver, array $readers): ConnectionPoolInterface|PDO|null
    {
        $host = (string) $env('db_host');
        $port = (int) ($env('db_port') ?? ($driver === 'pgsql' ? 5432 : 3306));
        $user = (string) ($env('db_user') ?? 'root');
        $password = (string) ($env('db_password') ?? '');
        $database = (string) ($env('db_database') ?? '');
        $charset = (string) ($env('db_charset') ?? ($driver === 'pgsql' ? 'utf8' : 'utf8mb4'));

        try {
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

            return new ConnectionPool($writerConn, $readerConns);
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
            if ($host === null) {
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
