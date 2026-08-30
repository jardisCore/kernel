<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

/**
 * Reads one key from the process environment, or `null` when it is unset or
 * empty.
 *
 * The one place the packer touches `getenv()` — injected into
 * {@see ResolveSecretKeyProvider} so the key chain can be exercised without a
 * real process environment.
 */
final class ReadProcessEnv
{
    public function __invoke(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
