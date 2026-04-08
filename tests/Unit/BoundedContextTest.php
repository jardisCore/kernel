<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit;

use Exception;
use JardisCore\Kernel\BoundedContext;
use JardisCore\Kernel\Response\ContextResponse;
use JardisCore\Kernel\DomainKernel;
use JardisSupport\Contract\ClassVersion\ClassVersionInterface;
use JardisSupport\Contract\Kernel\BoundedContextInterface;
use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Factory\Factory;
use PHPUnit\Framework\TestCase;
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
        $kernel = new DomainKernel('/app/src');
        $bc = new BoundedContext($kernel);

        $this->assertInstanceOf(BoundedContextInterface::class, $bc);
    }

    public function testHandleResolvesViaFactory(): void
    {
        $kernel = new DomainKernel('/app/src');
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    public function testHandleWithRegisteredInstance(): void
    {
        $expected = new stdClass();
        $expected->value = 'resolved';

        $factory = new Factory(instances: [
            stdClass::class => $expected,
        ]);

        $kernel = new DomainKernel('/app/src', container: $factory);
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertSame($expected, $result);
    }

    public function testHandleWithClassVersion(): void
    {
        $proxyObject = new stdClass();
        $proxyObject->value = 'versioned';

        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->method('__invoke')
            ->with(stdClass::class, '1.0')
            ->willReturn($proxyObject);

        $factory = new Factory(instances: [
            ClassVersionInterface::class => $classVersion,
        ]);

        $kernel = new DomainKernel('/app/src', container: $factory);
        $bc = new BoundedContext($kernel, null, '1.0');

        $result = $bc->handle(stdClass::class);

        $this->assertSame($proxyObject, $result);
    }

    public function testHandleWithoutClassVersion(): void
    {
        $kernel = new DomainKernel('/app/src');
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    public function testHandleClassVersionProxy(): void
    {
        $proxyObject = new stdClass();
        $proxyObject->proxy = true;

        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->method('__invoke')->willReturn($proxyObject);

        $factory = new Factory(instances: [
            ClassVersionInterface::class => $classVersion,
        ]);

        $kernel = new DomainKernel('/app/src', container: $factory);
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertSame($proxyObject, $result);
    }

    public function testHandleClassVersionReturnsNull(): void
    {
        $classVersion = $this->createMock(ClassVersionInterface::class);
        $classVersion->method('__invoke')->willReturn(null);

        $expected = new stdClass();
        $expected->fallback = true;

        $factory = new Factory(instances: [
            ClassVersionInterface::class => $classVersion,
            stdClass::class => $expected,
        ]);

        $kernel = new DomainKernel('/app/src', container: $factory);
        $bc = new BoundedContext($kernel);

        $result = $bc->handle(stdClass::class);

        $this->assertSame($expected, $result);
    }

    public function testHandleBoundedContextSubclass(): void
    {
        $kernel = new DomainKernel('/app/src');
        $bc = new BoundedContext($kernel, ['test' => true], '1.0');

        $result = $bc->handle(BoundedContext::class);

        $this->assertInstanceOf(BoundedContextInterface::class, $result);
    }

    public function testHandleLogsOnException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('not found'),
                $this->arrayHasKey('exception')
            );

        $kernel = new DomainKernel('/app/src', logger: $logger);
        $bc = new BoundedContext($kernel);

        $this->expectException(Exception::class);

        $bc->handle('NonExistent\\ClassName');
    }

    public function testGetResultCreatesContextResult(): void
    {
        $kernel = new DomainKernel('/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);
        $method = $reflection->getMethod('result');
        $method->setAccessible(true);

        $result = $method->invoke($bc);

        $this->assertInstanceOf(ContextResponseInterface::class, $result);
        $this->assertInstanceOf(ContextResponse::class, $result);
    }

    public function testGetResultIsCached(): void
    {
        $kernel = new DomainKernel('/app/src');
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
        $kernel = new DomainKernel('/app/src');
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
        $kernel = new DomainKernel('/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);
        $method = $reflection->getMethod('resource');
        $method->setAccessible(true);

        $this->assertSame($kernel, $method->invoke($bc));
    }

    public function testPayloadAndVersionAccessors(): void
    {
        $kernel = new DomainKernel('/app/src');
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
        $kernel = new DomainKernel('/app/src');
        $bc = new BoundedContext($kernel);

        $reflection = new \ReflectionClass($bc);

        $payloadMethod = $reflection->getMethod('payload');
        $payloadMethod->setAccessible(true);
        $this->assertNull($payloadMethod->invoke($bc));

        $versionMethod = $reflection->getMethod('version');
        $versionMethod->setAccessible(true);
        $this->assertSame('', $versionMethod->invoke($bc));
    }
}
