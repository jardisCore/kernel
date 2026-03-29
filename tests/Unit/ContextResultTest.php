<?php

declare(strict_types=1);

namespace JardisCore\Domain\Tests\Unit;

use JardisCore\Domain\ContextResult;
use JardisPort\Domain\ContextResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for ContextResult
 *
 * Focus: Context-keyed data, events, errors, nested results
 * Strategy: Pure PHP logic, no infrastructure needed
 */
class ContextResultTest extends TestCase
{
    public function testImplementsContextResultInterface(): void
    {
        $result = new ContextResult('OrderContext');

        $this->assertInstanceOf(ContextResultInterface::class, $result);
    }

    public function testAddDataStoresKeyValuePair(): void
    {
        $result = new ContextResult('OrderContext');
        $result->addData('orderId', 123);

        $data = $result->getData();

        $this->assertArrayHasKey('OrderContext', $data);
        $this->assertSame(123, $data['OrderContext']['orderId']);
    }

    public function testSetDataReplacesExistingData(): void
    {
        $result = new ContextResult('OrderContext');
        $result->addData('key1', 'value1');
        $result->setData(['key2' => 'value2']);

        $data = $result->getData();

        $this->assertArrayNotHasKey('key1', $data['OrderContext']);
        $this->assertSame('value2', $data['OrderContext']['key2']);
    }

    public function testGetDataReturnsContextKeyedArray(): void
    {
        $result = new ContextResult('PlaceOrder');
        $result->setData(['orderId' => 'ORD-123', 'total' => 99.90]);

        $data = $result->getData();

        $this->assertArrayHasKey('PlaceOrder', $data);
        $this->assertSame('ORD-123', $data['PlaceOrder']['orderId']);
        $this->assertSame(99.90, $data['PlaceOrder']['total']);
    }

    public function testGetDataReturnsEmptyArrayForContext(): void
    {
        $result = new ContextResult('EmptyContext');

        $data = $result->getData();

        $this->assertArrayHasKey('EmptyContext', $data);
        $this->assertEmpty($data['EmptyContext']);
    }

    public function testAddEventStoresEvent(): void
    {
        $result = new ContextResult('OrderContext');
        $event = new class {
            public string $type = 'OrderCreated';
        };

        $result->addEvent($event);

        $events = $result->getEvents();

        $this->assertArrayHasKey('OrderContext', $events);
        $this->assertCount(1, $events['OrderContext']);
        $this->assertSame($event, $events['OrderContext'][0]);
    }

    public function testGetEventsReturnsContextKeyedArray(): void
    {
        $result = new ContextResult('PlaceOrder');
        $event1 = new class {
            public string $type = 'OrderCreated';
        };
        $event2 = new class {
            public string $type = 'InventoryReserved';
        };

        $result->addEvent($event1);
        $result->addEvent($event2);

        $events = $result->getEvents();

        $this->assertArrayHasKey('PlaceOrder', $events);
        $this->assertCount(2, $events['PlaceOrder']);
    }

    public function testGetEventsReturnsEmptyArrayForContext(): void
    {
        $result = new ContextResult('EmptyContext');

        $events = $result->getEvents();

        $this->assertArrayHasKey('EmptyContext', $events);
        $this->assertEmpty($events['EmptyContext']);
    }

    public function testAddErrorStoresErrorMessage(): void
    {
        $result = new ContextResult('OrderContext');
        $result->addError('Validation failed');

        $errors = $result->getErrors();

        $this->assertArrayHasKey('OrderContext', $errors);
        $this->assertCount(1, $errors['OrderContext']);
        $this->assertSame('Validation failed', $errors['OrderContext'][0]);
    }

    public function testGetErrorsReturnsContextKeyedArray(): void
    {
        $result = new ContextResult('PlaceOrder');
        $result->addError('Stock insufficient');
        $result->addError('Payment declined');

        $errors = $result->getErrors();

        $this->assertArrayHasKey('PlaceOrder', $errors);
        $this->assertCount(2, $errors['PlaceOrder']);
    }

    public function testGetErrorsReturnsEmptyArrayForContext(): void
    {
        $result = new ContextResult('SuccessContext');

        $errors = $result->getErrors();

        $this->assertArrayHasKey('SuccessContext', $errors);
        $this->assertEmpty($errors['SuccessContext']);
    }

    public function testAddResultStoresSubResult(): void
    {
        $main = new ContextResult('OrderContext');
        $sub = new ContextResult('InventoryContext');

        $main->addResult($sub);

        $results = $main->getResults();

        $this->assertCount(1, $results);
        $this->assertSame($sub, $results[0]);
    }

    public function testMultipleSubResults(): void
    {
        $main = new ContextResult('WorkflowContext');
        $sub1 = new ContextResult('Step1');
        $sub2 = new ContextResult('Step2');
        $sub3 = new ContextResult('Step3');

        $main->addResult($sub1);
        $main->addResult($sub2);
        $main->addResult($sub3);

        $this->assertCount(3, $main->getResults());
    }

    public function testSubResultsHaveOwnContextKeys(): void
    {
        $main = new ContextResult('OrderContext');
        $main->addData('orderId', 123);

        $sub = new ContextResult('InventoryContext');
        $sub->addData('stockLevel', 50);

        $main->addResult($sub);

        $this->assertArrayHasKey('OrderContext', $main->getData());
        $this->assertArrayHasKey('InventoryContext', $sub->getData());
        $this->assertSame(123, $main->getData()['OrderContext']['orderId']);
        $this->assertSame(50, $sub->getData()['InventoryContext']['stockLevel']);
    }

    public function testFluentInterfaceAllowsMethodChaining(): void
    {
        $result = new ContextResult('TestContext');

        $returned = $result
            ->addData('key1', 'value1')
            ->addData('key2', 'value2')
            ->addEvent(new class {
            })
            ->addError('Error message')
            ->addResult(new ContextResult('SubContext'));

        $this->assertSame($result, $returned, 'Methods should return $this for chaining');
    }

    public function testGetContextIsProtected(): void
    {
        $reflection = new \ReflectionMethod(ContextResult::class, 'getContext');

        $this->assertTrue($reflection->isProtected());
    }
}
