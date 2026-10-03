<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Data;

/**
 * Available cache layer types for the CACHE_LAYERS ENV configuration.
 */
enum CacheLayer: string
{
    case Memory = 'memory';
    case Apcu = 'apcu';
    case Redis = 'redis';
    case Database = 'db';
}
