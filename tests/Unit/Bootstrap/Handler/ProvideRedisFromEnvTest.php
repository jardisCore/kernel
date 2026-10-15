<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\ProvideRedisFromEnv;
use JardisCore\Kernel\Exception\InvalidEnvConfigurationException;
use PHPUnit\Framework\TestCase;

final class ProvideRedisFromEnvTest extends TestCase
{
    public function testNoHostYieldsNoProvider(): void
    {
        self::assertNull((new ProvideRedisFromEnv())($this->envFrom([])));
    }

    public function testConfiguredHostYieldsProviderWithoutConnecting(): void
    {
        $provider = (new ProvideRedisFromEnv())($this->envFrom([
            'redis_host' => 'nonexistent_host_that_does_not_exist',
        ]));

        // Reaching this line proves nothing connected while providing.
        self::assertInstanceOf(Closure::class, $provider);
    }

    public function testUnreachableHostThrowsOnlyWhenTheProviderIsCalled(): void
    {
        $provider = (new ProvideRedisFromEnv())($this->envFrom([
            'redis_host' => 'nonexistent_host_that_does_not_exist',
        ]));
        self::assertNotNull($provider);

        $this->expectException(InvalidEnvConfigurationException::class);

        $provider();
    }

    public function testReachableRedisIsConnectedOnceAndShared(): void
    {
        $provider = (new ProvideRedisFromEnv())($this->envFrom([
            'redis_host' => 'kernel-test-redis',
            'redis_port' => 6379,
        ]));
        self::assertNotNull($provider);

        $redis = $provider();

        self::assertSame($redis, $provider());
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
