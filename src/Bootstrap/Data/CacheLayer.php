<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Data;

/**
 * Available cache layer types for the CACHE_LAYERS ENV configuration.
 *
 * Ported 1:1 from `jardiscore/foundation` (`JardisCore\Foundation\Data\CacheLayer`,
 * Kernel-Entkopplung P2) into the Bootstrap-Packer sub-namespace.
 */
enum CacheLayer: string
{
    case Memory = 'memory';
    case Apcu = 'apcu';
    case Redis = 'redis';
    case Database = 'db';
}
