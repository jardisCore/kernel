<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Integration\Bootstrap;

use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisCore\Kernel\Bootstrap\Handler\BuildConnectionPoolConfigFromEnv;
use JardisCore\Kernel\Tests\Support\LoadEnvGetFromRealCascade;
use PHPUnit\Framework\TestCase;

/**
 * AK2.1 (PLAN.md §P2) — `DB_POOL_VALIDATE_CONNECTIONS` / `DB_POOL_STICKY_WRITER`
 * through a REAL DotEnv cascade.
 *
 * Gegenprobe: pre-R1 code compared the raw value `=== 'true'`. A bare `"1"`
 * in a `.env` file is cast to `int(1)` by DotEnv (CastStringToNumeric runs
 * before CastStringToBool) — `1 === 'true'` is `false` in PHP, so the old
 * code silently read `DB_POOL_STICKY_WRITER=1` as "off". This test proves
 * the fixed handler reads it as `true`.
 */
final class DbPoolBoolCascadeTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../Fixtures/Bootstrap/' . $name;
    }

    public function testTrueLiteralSetsBothFlagsTrue(): void
    {
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('BoolCasting/TrueLiteral'));
        $config = (new BuildConnectionPoolConfigFromEnv())($env);

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertTrue($config->validateConnections);
        self::assertTrue($config->stickyWriterDuringTransaction);
    }

    public function testFalseLiteralSetsBothFlagsFalse(): void
    {
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('BoolCasting/FalseLiteral'));
        $config = (new BuildConnectionPoolConfigFromEnv())($env);

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertFalse($config->validateConnections);
        self::assertFalse($config->stickyWriterDuringTransaction);
    }

    public function testNumericOneAndZeroAreReadAsBooleans(): void
    {
        // DB_POOL_VALIDATE_CONNECTIONS=1 -> DotEnv casts to int(1) -> true.
        // DB_POOL_STICKY_WRITER=0 -> int(0) -> false. The Roh-String
        // comparison this replaces (`=== 'true'`) would read BOTH as false.
        $env = (new LoadEnvGetFromRealCascade())($this->fixturePath('BoolCasting/OneZero'));
        $config = (new BuildConnectionPoolConfigFromEnv())($env);

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertTrue($config->validateConnections);
        self::assertFalse($config->stickyWriterDuringTransaction);
    }
}
