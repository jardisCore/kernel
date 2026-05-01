<?php

declare(strict_types=1);

namespace FakeDomain\Bc\Agg\Command\Handler;

/**
 * Generator-base Handler. Has a baseline Extensions override in
 * FakeDomain\Bc\Agg\Extensions\Command\Handler\BaselineHandler.
 */
class BaselineHandler
{
    public function origin(): string
    {
        return 'generator';
    }
}
