<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Data\CacheLayer;

/**
 * Decides whether the packer must hand the writer PDO to other handlers.
 *
 * Extracting the writer PDO from a lazy pool opens the connection
 * ({@see ExtractPdoFromConnection}). It is only worth doing at bootstrap when
 * a consumer needs a PDO *object* up front: the `db` cache layer
 * (`CACHE_LAYERS` contains `db`) or the `database` messaging transport
 * (`MESSAGING_TRANSPORT=database`). Otherwise the connection stays untouched
 * until the domain first uses it.
 */
final class IsWriterPdoRequired
{
    /** @param Closure(string): mixed $env */
    public function __invoke(Closure $env): bool
    {
        $layers = $env('cache_layers');
        if (!(new IsEnvValueUnset())($layers)) {
            $names = array_map('trim', explode(',', (string) $layers));
            if (in_array(CacheLayer::Database->value, $names, true)) {
                return true;
            }
        }

        $transport = $env('messaging_transport');

        return !(new IsEnvValueUnset())($transport)
            && strtolower((string) $transport) === 'database';
    }
}
