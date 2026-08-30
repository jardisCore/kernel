<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

/**
 * Lowercases every ENV key so lookups are case-insensitive.
 *
 * `.env` files write keys in UPPERCASE, callers of {@see \JardisCore\Kernel\DomainKernel::env()}
 * and every Handler closure read them in lowercase — normalizing once, here,
 * is what makes both spellings the same key instead of leaving each consumer
 * to remember a `strtolower()`.
 */
final class NormalizeEnvKeys
{
    /**
     * @param array<string, mixed> $env
     * @return array<string, mixed>
     */
    public function __invoke(array $env): array
    {
        return array_change_key_case($env, CASE_LOWER);
    }
}
