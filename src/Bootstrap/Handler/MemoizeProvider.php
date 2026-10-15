<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use Closure;

/**
 * Wraps a builder in a provider that runs it at most once (Lazy
 * Initialization): the first call builds, every later call returns the same
 * instance.
 *
 * This is how the packer hands out an external resource (the writer PDO, the
 * Redis client) without opening it: the consumer that needs the object calls
 * the provider at its own first use, and the bootstrap itself never does.
 */
final class MemoizeProvider
{
    /**
     * @template T of object
     * @param Closure(): T $build
     * @return Closure(): T
     */
    public function __invoke(Closure $build): Closure
    {
        $instance = null;

        return static function () use (&$instance, $build) {
            /** @var T */
            return $instance ??= $build();
        };
    }
}
