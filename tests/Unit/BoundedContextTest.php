<?php

declare(strict_types=1);

namespace JardisCore\Domain\Tests\Unit;

use Exception;
use JardisCore\Domain\BoundedContext;
use JardisCore\Domain\ContextResult;
use JardisCore\Domain\DomainKernel;
use JardisPort\ClassVersion\ClassVersionInterface;
use JardisPort\Domain\BoundedContextInterface;
use JardisPort\Domain\ContextResultInterface;
use JardisPort\Domain\DomainKernelInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Unit Tests for BoundedContext
 *
 * Focus: Container resolution, ClassVersion discovery, result creation, error logging
 */
class BoundedContextTest extends TestCase
{
    public function testImplementsBoundedContextInterface(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $bc = new BoundedContext($kernel);

        $this->assertInstanceOf(BoundedContextInterface::class, $bc);
    }

    public function testHandleWithPsr11Container(): void
    {
        $expected = new stdClass();
        $expected->value = 'resolved';

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->with(stdClass::class)->willReturn($expected);

        $kernel = new DomainKernel('/app', '/app/src', container: $container);
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertSame($expected, $result);
    }

    public function testHandleWithClassVersion(): void
    {
        $expected = new stdClass();
        $expected->value = 'versioned';

        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->method('__invoke')
            ->with(stdClass::class, '1.0')
            ->willReturn('Versioned\\StdClass');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(fn(string $id) => $id === ClassVersionInterface::class);
        $container->method('get')
            ->willReturnCallback(function (string $id) use ($classVersion, $expected) {
                if ($id === ClassVersionInterface::class) {
                    return $classVersion;
                }
                if ($id === 'Versioned\\StdClass') {
                    return $expected;
                }
                return null;
            });

        $kernel = new DomainKernel('/app', '/app/src', container: $container);
        $bc = new BoundedContext($kernel, null, '1.0');

        $result = $bc->handle(stdClass::class);

        $this->assertSame($expected, $result);
    }

    public function testHandleWithoutClassVersion(): void
    {
        $expected = new stdClass();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->with(stdClass::class)->willReturn($expected);

        $kernel = new DomainKernel('/app', '/app/src', container: $container);
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertSame($expected, $result);
    }

    public function testHandleClassVersionProxy(): void
    {
        $proxyObject = new stdClass();
        $proxyObject->proxy = true;

        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->method('__invoke')->willReturn($proxyObject);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(fn(string $id) => $id === ClassVersionInterface::class);
        $container->method('get')
            ->with(ClassVersionInterface::class)
            ->willReturn($classVersion);

        $kernel = new DomainKernel('/app', '/app/src', container: $container);
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertSame($proxyObject, $result);
    }

    public function testHandleLogsOnException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Container not available'),
                $this->arrayHasKey('exception')
            );

        $kernel = new DomainKernel('/app', '/app/src', logger: $logger);
        $bc = new BoundedContext($kernel);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Container not available');

        $bc->handle(stdClass::class);
    }

    public function testHandleWithoutContainerThrows(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $bc = new BoundedContext($kernel);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Container not available');

        $bc->handle(stdClass::class);
    }

    public function testGetResultCreatesContextResult(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);
        $method = $reflection->getMethod('result');
        $method->setAccessible(true);

        $result = $method->invoke($bc);

        $this->assertInstanceOf(ContextResultInterface::class, $result);
        $this->assertInstanceOf(ContextResult::class, $result);
    }

    public function testGetResultIsCached(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);
        $method = $reflection->getMethod('result');
        $method->setAccessible(true);

        $result1 = $method->invoke($bc);
        $result2 = $method->invoke($bc);

        $this->assertSame($result1, $result2);
    }

    public function testGetResultUsesClassName(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);
        $method = $reflection->getMethod('result');
        $method->setAccessible(true);

        $result = $method->invoke($bc);
        $data = $result->getData();

        $this->assertArrayHasKey('BoundedContext', $data);
    }

    public function testResourceReturnsKernel(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);
        $method = $reflection->getMethod('resource');
        $method->setAccessible(true);

        $this->assertSame($kernel, $method->invoke($bc));
    }

    public function testPayloadAndVersionAccessors(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $payload = ['orderId' => 123];
        $bc = new BoundedContext($kernel, $payload, '2.0');

        $reflection = new \ReflectionClass($bc);

        $payloadMethod = $reflection->getMethod('payload');
        $payloadMethod->setAccessible(true);
        $this->assertSame($payload, $payloadMethod->invoke($bc));

        $versionMethod = $reflection->getMethod('version');
        $versionMethod->setAccessible(true);
        $this->assertSame('2.0', $versionMethod->invoke($bc));
    }

    public function testDefaultPayloadAndVersion(): void
    {
        $kernel = new DomainKernel('/app', '/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);

        $payloadMethod = $reflection->getMethod('payload');
        $payloadMethod->setAccessible(true);
        $this->assertNull($payloadMethod->invoke($bc));

        $versionMethod = $reflection->getMethod('version');
        $versionMethod->setAccessible(true);
        $this->assertSame('', $versionMethod->invoke($bc));
    }

    public function testHandleClassVersionReturnsNull(): void
    {
        $expected = new stdClass();

        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->method('__invoke')->willReturn(null);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(fn(string $id) => $id === ClassVersionInterface::class);
        $container->method('get')
            ->willReturnCallback(function (string $id) use ($classVersion, $expected) {
                if ($id === ClassVersionInterface::class) {
                    return $classVersion;
                }
                if ($id === stdClass::class) {
                    return $expected;
                }
                return null;
            });

        $kernel = new DomainKernel('/app', '/app/src', container: $container);
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertSame($expected, $result);
    }
}
