<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisCore\Kernel\Bootstrap\Data\CredentialEnvKeySuffixes;
use JardisSupport\DotEnv\DotEnv;
use JardisSupport\Secret\Handler\SecretHandler;

/**
 * Loads a config path's private `.env` cascade on a fresh DotEnv instance.
 *
 * DotEnv is built per call because `addHandler()` mutates the instance — a
 * shared one would accumulate secret handlers across packer invocations with
 * different project roots. The secret handler is prepended so decrypted values
 * still pass the cast chain; credential raw keys
 * ({@see CredentialEnvKeySuffixes}) skip that chain entirely, so the same
 * handler runs once more over the loaded string values.
 */
final class LoadEnvFromConfigPath
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $configPath, ?SecretHandler $secretHandler): array
    {
        $dotEnv = new DotEnv();
        $dotEnv->addRawKeys(CredentialEnvKeySuffixes::SUFFIXES);

        if ($secretHandler !== null) {
            $dotEnv->addHandler($secretHandler, prepend: true);
        }

        $env = $dotEnv->loadPrivate($configPath);

        if ($secretHandler === null) {
            return $env;
        }

        foreach ($env as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $secretHandler($value);
            }
        }

        return $env;
    }
}
