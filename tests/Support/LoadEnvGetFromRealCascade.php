<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Support;

use Closure;
use JardisCore\Kernel\Bootstrap\Data\CredentialEnvKeySuffixes;
use JardisCore\Kernel\Bootstrap\Handler\NormalizeEnvKeys;
use JardisCore\Kernel\Bootstrap\Handler\ReadEnvValue;
use JardisSupport\DotEnv\DotEnv;

/**
 * Builds a Handler-shaped `Closure(string): mixed` from a REAL DotEnv cascade
 * over a fixture project root — mirrors `BuildDomainKernelFromEnv`'s own
 * `$envGet` by reusing the very units it is built from (raw-key registration,
 * {@see NormalizeEnvKeys}, {@see ReadEnvValue}), so the two can never drift.
 *
 * Exists so Integration tests can exercise Handlers against values that
 * actually passed through DotEnv's cast chain instead of a synthetic
 * Roh-String closure (BEFUNDE.md §1c: the old Unit tests fed raw strings
 * directly and missed the R1 bugs because of exactly that shortcut).
 */
final class LoadEnvGetFromRealCascade
{
    /** @return Closure(string): mixed */
    public function __invoke(string $projectRoot): Closure
    {
        $dotEnv = new DotEnv();
        $dotEnv->addRawKeys(CredentialEnvKeySuffixes::SUFFIXES);
        $env = (new NormalizeEnvKeys())($dotEnv->loadPrivate($projectRoot));
        $readEnvValue = (new ReadEnvValue())->__invoke(...);

        return static fn (string $key): mixed => $readEnvValue($env, $key);
    }
}
