<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Support;

use Closure;
use JardisCore\Kernel\Bootstrap\Data\CredentialEnvKeySuffixes;
use JardisSupport\DotEnv\DotEnv;

/**
 * Builds a Handler-shaped `Closure(string): mixed` from a REAL DotEnv cascade
 * over a fixture directory — mirrors `BuildDomainKernelFromEnv`'s own
 * `$envGet` (raw-key registration, lowercase keys, `''` -> `null`), minus the
 * `$_ENV` fallback (irrelevant for isolated Handler tests).
 *
 * Exists so Integration tests can exercise Handlers against values that
 * actually passed through DotEnv's cast chain instead of a synthetic
 * Roh-String closure (BEFUNDE.md §1c: the old Unit tests fed raw strings
 * directly and missed the R1 bugs because of exactly that shortcut).
 */
final class LoadEnvGetFromRealCascade
{
    /** @return Closure(string): mixed */
    public function __invoke(string $configPath): Closure
    {
        $dotEnv = new DotEnv();
        $dotEnv->addRawKeys(CredentialEnvKeySuffixes::SUFFIXES);
        $env = array_change_key_case($dotEnv->loadPrivate($configPath), CASE_LOWER);

        return static function (string $key) use ($env): mixed {
            $value = $env[strtolower($key)] ?? null;
            return $value === '' ? null : $value;
        };
    }
}
