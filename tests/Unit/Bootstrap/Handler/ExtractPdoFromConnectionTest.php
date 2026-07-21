<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Bootstrap\Handler;

use JardisCore\Kernel\Bootstrap\Handler\ExtractPdoFromConnection;
use JardisSupport\Contract\DbConnection\ConnectionPoolInterface;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExtractPdoFromConnectionTest extends TestCase
{
    public function testReturnsNullForNullConnection(): void
    {
        self::assertNull((new ExtractPdoFromConnection())(null));
    }

    public function testReturnsPdoDirectlyWhenConnectionIsAlreadyPdo(): void
    {
        $pdo = new PDO('sqlite::memory:');

        self::assertSame($pdo, (new ExtractPdoFromConnection())($pdo));
    }

    public function testExtractsPdoFromConnectionPoolWriter(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $writer = $this->createMock(DbConnectionInterface::class);
        $writer->method('pdo')->willReturn($pdo);

        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->method('getWriter')->willReturn($writer);

        self::assertSame($pdo, (new ExtractPdoFromConnection())($pool));
    }
}
