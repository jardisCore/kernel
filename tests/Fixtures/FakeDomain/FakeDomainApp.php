<?php

declare(strict_types=1);

namespace FakeDomain;

use JardisCore\Kernel\DomainApp;

/**
 * Concrete DomainApp subclass rooted under tests/Fixtures/FakeDomain.
 *
 * Used by DomainAppExtensionsResolutionTest to verify that Extensions-style
 * overrides resolved by LoadClassFromExtensions are picked up when handle()
 * is called from Domain level.
 */
class FakeDomainApp extends DomainApp
{
}
