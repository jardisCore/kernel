<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Bootstrap\Handler;

use JardisCore\Kernel\Bootstrap\Data\CredentialEnvKeySuffixes;
use JardisSupport\DotEnv\DotEnv;
use JardisSupport\Secret\Handler\SecretHandler;

/**
 * Loads the packer's ENV — from the project root's `.env` cascade or from an
 * in-memory `.env`-formatted string — on a fresh DotEnv instance.
 *
 * This unit owns the file-vs-string decision so the orchestrator stays
 * branch-free: `$envContent === null` means "read `<projectRoot>/.env` and its
 * cascade" (`.env` -> `.env.local` -> `.env.{APP_ENV}`); anything else,
 * INCLUDING the empty string, means "this string IS the configuration" — the
 * dateless container case, where every value arrives through the process
 * environment. Exclusive there means exclusive against the FILE: the file is
 * not consulted, not even per key. The process environment still wins over
 * both (dotenv >= 1.4.0).
 *
 * Raw keys and the secret handler are wired ONCE, for both modes:
 * credential-shaped keys ({@see CredentialEnvKeySuffixes}) skip the cast chain
 * so `DB_PASSWORD=123456` stays a string, and the secret handler is prepended
 * so `secret(...)` values decrypt BEFORE any cast runs. Raw keys only skip the
 * casts — since dotenv 1.3 registered value handlers still reach them, so one
 * pass decrypts every value.
 *
 * DotEnv is built per call because `addHandler()` mutates the instance — a
 * shared one would accumulate secret handlers across packer invocations with
 * different project roots.
 */
final class LoadEnvForKernel
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $projectRoot, ?string $envContent, ?SecretHandler $secretHandler): array
    {
        $dotEnv = new DotEnv();
        $dotEnv->addRawKeys(CredentialEnvKeySuffixes::SUFFIXES);

        if ($secretHandler !== null) {
            $dotEnv->addHandler($secretHandler, prepend: true);
        }

        if ($envContent === null) {
            return $dotEnv->loadPrivate($projectRoot);
        }

        return $dotEnv->loadPrivateFromString($envContent, baseDir: $projectRoot);
    }
}
