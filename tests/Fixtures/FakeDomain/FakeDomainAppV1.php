<?php

declare(strict_types=1);

namespace FakeDomain;

/**
 * Variant of FakeDomainApp that activates Extensions\v1 resolution by
 * returning 'v1' from version().
 */
class FakeDomainAppV1 extends FakeDomainApp
{
    protected function version(): string
    {
        return 'v1';
    }
}
