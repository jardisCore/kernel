<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Data;

/**
 * Credential-shaped ENV key suffixes the packer registers as DotEnv raw keys
 * (`DotEnv::addRawKeys()`) before loading — their values survive the cast
 * chain as strings instead of being silently turned into bool/int/etc.
 * (R1.2, G6). Matching is case-insensitive suffix match (`MatchesRawKey`).
 */
final class CredentialEnvKeySuffixes
{
    /** @var array<string> */
    public const array SUFFIXES = [
        '_PASSWORD',
        '_USER',
        '_SECRET',
        '_TOKEN',
    ];
}
