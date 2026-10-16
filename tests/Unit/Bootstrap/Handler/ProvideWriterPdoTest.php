<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use JardisCore\Kernel\Bootstrap\Handler\ProvideWriterPdo;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProvideWriterPdoTest extends TestCase
{
    public function testNoConnectionYieldsNoProvider(): void
    {
        self::assertNull((new ProvideWriterPdo())(null));
    }

    public function testPoolIsNotTouchedUntilTheProviderIsCalledAndPdoIsShared(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $writer = $this->createMock(DbConnectionInterface::class);
        $writer->expects(self::once())->method('pdo')->willReturn($pdo);
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('getWriter')->willReturn($writer);

        $provider = (new ProvideWriterPdo())($pool);

        self::assertNotNull($provider);
        // Calling the provider is the first access: only now the writer is asked.
        self::assertSame($pdo, $provider());
        self::assertSame($pdo, $provider());
    }

    public function testPlainPdoIsProvidedAsIs(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $provider = (new ProvideWriterPdo())($pdo);

        self::assertNotNull($provider);
        self::assertSame($pdo, $provider());
    }
}
