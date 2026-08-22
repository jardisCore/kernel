<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\DbConnection\Config\MySqlConfig;
use JardisCore\Kernel\Tests\Support\LoadEnvGetFromRealCascade;
use PHPUnit\Framework\TestCase;

/**
 * AK2.3 (PLAN.md §P2) — `DB_PASSWORD=false` survives the real DotEnv cascade
 * as the string `'false'`, not the DotEnv-cast `bool(false)` (BEFUNDE.md §1d).
 *
 * This repo's compose has no reachable MySQL service (kernel QA has no
 * Docker dependency — see `BuildConnectionFromEnvTest`'s docblock), so a
 * live `ConnectionFactory::mysql()` round-trip cannot observe the resulting
 * `MySqlConfig` (it connects eagerly and never returns a config handle on
 * failure). This test instead proves the exact boundary
 * `BuildConnectionFromEnv` crosses: the string produced by the real cascade
 * (`(string) $envGet('db_password')`, same expression the handler uses)
 * reaches the adapter's public `MySqlConfig` VO unchanged.
 */
final class CredentialCastExemptionTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/Bootstrap/' . $name;
    }

    public function testPasswordLiteralFalseSurvivesAsString(): void
    {
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('CredentialRawKeys'));

        // Without the raw-key registration this would be bool(false) — the
        // Cast-Kette default behaviour BEFUNDE.md §1d documents as the bug.
        self::assertSame('false', $env('db_password'));

        $config = new MySqlConfig(
            host: 'irrelevant-for-this-test',
            user: 'irrelevant',
            password: (string) $env('db_password'),
            database: 'irrelevant',
        );

        self::assertSame('false', $config->password);
    }
}
