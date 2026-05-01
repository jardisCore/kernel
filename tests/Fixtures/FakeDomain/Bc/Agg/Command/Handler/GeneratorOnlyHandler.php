<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Command\Handler;

/**
 * Generator-base Handler without any Extensions override.
 * Resolution must fall through to this class.
 */
class GeneratorOnlyHandler
{
    public function origin(): string
    {
        return 'generator';
    }
}
