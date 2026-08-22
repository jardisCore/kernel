<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisCore\Kernel\Bootstrap\BuildDomainKernelFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AK2.4 (PLAN.md §P2) — the four G5 error rules (PRD R1.3) through the full
 * Bootstrap-Packer against real fixture `.env` files:
 * fehlend=null, fehlerhaft=Exception, unerreichbar=Exception (see the sibling
 * UnreachableServiceExceptionTest), unbekannt=ignoriert.
 */
final class ErrorDegradationRulesTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/ProjectRoot/' . $name;
    }

    public function testEmptyDbHostDegradesToNullNotToAnEmptyHostAttempt(): void
    {
        // DB_HOST= (empty value) is "missing", not "set to the empty
        // string" (R1.3 rule 1) — no connection attempt, no exception.
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('EmptyDbHost'));

        self::assertNull($kernel->dbConnection());
    }

    public function testUnknownForeignKeyIsIgnoredBootStaysUnaffected(): void
    {
        // FOO_BAR=x is not a canonical key of any handler — R1.3 rule 4.
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('UnknownForeignKey'));

        self::assertNull($kernel->dbConnection());
        self::assertNull($kernel->mailer());
        self::assertNull($kernel->env('foo_bar_does_not_exist'));
    }

    public function testMissingDbConfigurationDegradesToNull(): void
    {
        // No DB_* key at all, default driver mysql, no host -> null (Bestand).
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('UnknownForeignKey'));

        self::assertNull($kernel->dbConnection());
    }

    public function testInvalidPoolStrategyThrowsInsteadOfSilentlyFallingBackToPdo(): void
    {
        // R1.3 rule 2 (fehlerhaft) — exactly the "Verschluck-Szenario"
        // BEFUNDE.md §1e documents: pre-R1 this fell back to a plain PDO
        // attempt (which then also failed and returned null) with only a
        // bare error_log — the invalid strategy itself never surfaced.
        $this->expectException(InvalidEnvConfigurationException::class);

        (new BuildDomainKernelFromEnv())($this->fixturePath('InvalidPoolStrategy'));
    }

    public function testSqliteInMemoryStillWorksAsTheUnconfiguredDefault(): void
    {
        // Control case: DB_DRIVER=sqlite with an in-memory path never fails,
        // so the "configured" path still succeeds without ever entering the
        // new exception branch — G5 rule 3 only fires on an actual failure.
        $kernel = (new BuildDomainKernelFromEnv())($this->fixturePath('FullConfig'));

        self::assertInstanceOf(PDO::class, $kernel->dbConnection());
    }
}
