<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use Closure;
use JardisCore\Kernel\Bootstrap\Handler\IsWriterPdoRequired;
use PHPUnit\Framework\TestCase;

final class IsWriterPdoRequiredTest extends TestCase
{
    public function testNotRequiredWithoutConsumers(): void
    {
        self::assertFalse((new IsWriterPdoRequired())($this->envFrom([])));
    }

    public function testNotRequiredForMemoryAndRedisCacheLayers(): void
    {
        self::assertFalse((new IsWriterPdoRequired())($this->envFrom(['cache_layers' => 'memory, redis'])));
    }

    public function testRequiredForDatabaseCacheLayer(): void
    {
        self::assertTrue((new IsWriterPdoRequired())($this->envFrom(['cache_layers' => 'memory, db'])));
    }

    public function testRequiredForDatabaseMessagingTransport(): void
    {
        self::assertTrue((new IsWriterPdoRequired())($this->envFrom(['messaging_transport' => 'Database'])));
    }

    public function testNotRequiredForOtherMessagingTransports(): void
    {
        self::assertFalse((new IsWriterPdoRequired())($this->envFrom(['messaging_transport' => 'kafka'])));
    }

    public function testExplicitlyEmptyValuesCountAsUnset(): void
    {
        self::assertFalse((new IsWriterPdoRequired())($this->envFrom([
            'cache_layers' => false,
            'messaging_transport' => false,
        ])));
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
