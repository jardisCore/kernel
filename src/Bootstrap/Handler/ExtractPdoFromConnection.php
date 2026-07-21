<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use PDO;

/**
 * Extracts a plain PDO handle from the connection the packer already built —
 * feeds the optional `db` cache layer (`BuildCacheFromEnv`), which needs a PDO
 * regardless of whether the domain runs on a bare connection or a pool.
 *
 * Kept as its own Closure (Closure-Orchestrator: one atomic unit, one reason
 * to change) rather than inline match logic in the packer's `__invoke()`.
 */
final class ExtractPdoFromConnection
{
    public function __invoke(ConnectionPoolInterface|PDO|null $connection): ?PDO
    {
        return match (true) {
            $connection instanceof ConnectionPoolInterface => $connection->getWriter()->pdo(),
            $connection instanceof PDO => $connection,
            default => null,
        };
    }
}
