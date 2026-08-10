<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use InvalidArgumentException;
use JardisAdapter\DbConnection\Config\ConnectionPoolConfig;
use JardisAdapter\DbConnection\ConnectionPool;
use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisCore\Kernel\Bootstrap\Handler\BuildConnectionPoolConfigFromEnv;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BuildConnectionPoolConfigFromEnv — the optional DB_POOL_* config
 * consumed by BuildConnectionFromEnv's pool branch.
 *
 * The sticky-writer case is integration-tested against a real ConnectionPool
 * on SQLite in-memory connections (jardisadapter/dbconnection >= 1.1) — no
 * network, no Docker dependency, same constraint as the sibling test suites.
 */
final class BuildConnectionPoolConfigFromEnvTest extends TestCase
{
    public function testNoPoolKeysReturnsNullSoPoolKeepsAdapterDefaults(): void
    {
        // Null means BuildConnectionFromEnv keeps the two-argument
        // ConnectionPool construction — behaviour unchanged (A6).
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_host' => 'writer-host',
            'db_reader1_host' => 'reader-host',
        ]));

        self::assertNull($config);
    }

    public function testStickyWriterTrueBuildsConfigCarryingTheFlag(): void
    {
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_pool_sticky_writer' => 'true',
        ]));

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertTrue($config->stickyWriterDuringTransaction);
        // Unset keys keep the adapter defaults.
        self::assertTrue($config->validateConnections);
        self::assertSame(30, $config->healthCheckCacheTtl);
        self::assertSame(0, $config->healthCheckNegativeCacheTtl);
        self::assertSame(ConnectionPoolConfig::STRATEGY_ROUND_ROBIN, $config->loadBalancingStrategy);
    }

    public function testBoolKeysFollowKernelStringComparisonOnlyLiteralTrueIsTrue(): void
    {
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_pool_sticky_writer' => '1',
            'db_pool_validate_connections' => 'false',
        ]));

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertFalse($config->stickyWriterDuringTransaction);
        self::assertFalse($config->validateConnections);
    }

    public function testSingleIntKeyOnlyThatFieldDeviatesFromDefaults(): void
    {
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_pool_health_check_cache_ttl' => '5',
        ]));

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertSame(5, $config->healthCheckCacheTtl);
        self::assertTrue($config->validateConnections);
        self::assertSame(0, $config->healthCheckNegativeCacheTtl);
        self::assertSame(ConnectionPoolConfig::STRATEGY_ROUND_ROBIN, $config->loadBalancingStrategy);
        self::assertFalse($config->stickyWriterDuringTransaction);
    }

    public function testSingleStrategyKeyOnlyThatFieldDeviatesFromDefaults(): void
    {
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_pool_load_balancing_strategy' => 'random',
        ]));

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertSame(ConnectionPoolConfig::STRATEGY_RANDOM, $config->loadBalancingStrategy);
        self::assertTrue($config->validateConnections);
        self::assertSame(30, $config->healthCheckCacheTtl);
        self::assertSame(0, $config->healthCheckNegativeCacheTtl);
        self::assertFalse($config->stickyWriterDuringTransaction);
    }

    public function testNegativeCacheTtlKeyIsPassedThrough(): void
    {
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_pool_health_check_negative_cache_ttl' => '10',
        ]));

        self::assertInstanceOf(ConnectionPoolConfig::class, $config);
        self::assertSame(10, $config->healthCheckNegativeCacheTtl);
        self::assertSame(30, $config->healthCheckCacheTtl);
    }

    public function testInvalidStrategyPropagatesAdapterValidation(): void
    {
        // BuildConnectionFromEnv catches this in its existing \Throwable
        // fallback (plain PDO + error_log); the handler itself stays thin
        // and does not re-validate what the adapter already validates.
        $this->expectException(InvalidArgumentException::class);

        (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_pool_load_balancing_strategy' => 'no-such-strategy',
        ]));
    }

    public function testStickyWriterConfigReturnsWriterDuringOpenTransaction(): void
    {
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([
            'db_pool_sticky_writer' => 'true',
        ]));
        self::assertInstanceOf(ConnectionPoolConfig::class, $config);

        $factory = new ConnectionFactory();
        $writer = $factory->sqlite(':memory:');
        $reader = $factory->sqlite(':memory:');
        $pool = new ConnectionPool($writer, [$reader], $config);

        $writer->beginTransaction();

        try {
            self::assertSame($writer, $pool->getReader());
        } finally {
            $writer->rollback();
        }

        // Transaction closed — reads go back to the reader.
        self::assertSame($reader, $pool->getReader());
    }

    public function testWithoutStickyConfigPoolReturnsReaderDespiteOpenTransaction(): void
    {
        // Control case: null config = adapter defaults (sticky off) — the
        // pre-change behaviour BuildConnectionFromEnv keeps without DB_POOL_*.
        $config = (new BuildConnectionPoolConfigFromEnv())($this->envFrom([]));
        self::assertNull($config);

        $factory = new ConnectionFactory();
        $writer = $factory->sqlite(':memory:');
        $reader = $factory->sqlite(':memory:');
        $pool = new ConnectionPool($writer, [$reader]);

        $writer->beginTransaction();

        try {
            self::assertSame($reader, $pool->getReader());
        } finally {
            $writer->rollback();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return Closure(string): mixed
     */
    private function envFrom(array $data): Closure
    {
        return static fn (string $key): mixed => $data[strtolower($key)] ?? null;
    }
}
