<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Support;

use PDO;

/** Applies the token-table DDL that ships with jardissupport/auth (the package's own SQL, not a copy). */
final class AuthTokenTable
{
    public static function create(PDO $pdo): void
    {
        $ddl = file_get_contents(__DIR__ . '/../../vendor/jardissupport/auth/resources/sql/sqlite.sql');
        $pdo->exec((string) $ddl);
    }
}
