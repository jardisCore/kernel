<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use PDO;

/**
 * Lazy access to the writer PDO of the connection the packer already built.
 *
 * Replaces the eager extraction at bootstrap: the optional `db` cache layer and
 * the `database` messaging transport need a PDO *object*, but obtaining it
 * from a pool opens the connection ({@see ExtractPdoFromConnection}). The
 * returned provider defers exactly that to the consumer's first use and hands
 * the same PDO to every caller. No connection configured: no provider (`null`).
 */
final class ProvideWriterPdo
{
    /** @return (Closure(): PDO)|null */
    public function __invoke(ConnectionPoolInterface|PDO|null $connection): ?Closure
    {
        if ($connection === null) {
            return null;
        }

        return (new MemoizeProvider())(
            static fn (): PDO => (new ExtractPdoFromConnection())($connection)
        );
    }
}
