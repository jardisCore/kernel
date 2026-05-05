<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Platform\Command\Handler;

/**
 * Generator-base Handler under Platform/ without any dev-override.
 * Resolution must fall through to this class.
 */
class GeneratorOnlyHandler
{
    public function origin(): string
    {
        return 'generator';
    }
}
