<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Response;

use JardisCore\Kernel\Response\ContextResponse;
use JardisCore\Kernel\Response\DomainResponseTransformer;
use JardisCore\Kernel\Response\ResponseStatus;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for DomainResponseTransformer
 *
 * Focus: Aggregation from ContextResults, status resolution, metadata
 * Strategy: Pure PHP logic, no infrastructure needed
 */
class DomainResponseTransformerTest extends TestCase
{
    public function testTransformReturnsDomainResponseInterface(): void
    {
        $transformer = new DomainResponseTransformer();
        $result = new ContextResponse('PlaceOrder');

        $response = $transformer->transform($result);

        $this->assertInstanceOf(DomainResponseInterface::class, $response);
    }

    public function testTransformAggregatesData(): void
    {
        $transformer = new DomainResponseTransformer();

        $result = new ContextResponse('PlaceOrder');
        $result->setData(['orderId' => 'ORD-123']);

        $response = $transformer->transform($result);

        $this->assertArrayHasKey('PlaceOrder', $response->getData());
        $this->assertSame('ORD-123', $response->getData()['PlaceOrder']['orderId']);
    }

    public function testTransformAggregatesDataFromSubResults(): void
    {
        $transformer = new DomainResponseTransformer();

        $main = new ContextResponse('PlaceOrder');
        $main->setData(['orderId' => 'ORD-123']);

        $sub = new ContextResponse('CheckInventory');
        $sub->setData(['available' => true]);

        $main->addResult($sub);

        $response = $transformer->transform($main);
        $data = $response->getData();

        $this->assertArrayHasKey('PlaceOrder', $data);
        $this->assertArrayHasKey('CheckInventory', $data);
        $this->assertSame('ORD-123', $data['PlaceOrder']['orderId']);
        $this->assertTrue($data['CheckInventory']['available']);
    }

    public function testTransformAggregatesEvents(): void
    {
        $transformer = new DomainResponseTransformer();

        $event = new class {
            public string $type = 'OrderCreated';
        };

        $result = new ContextResponse('PlaceOrder');
        $result->addEvent($event);

        $response = $transformer->transform($result);
        $events = $response->getEvents();

        $this->assertArrayHasKey('PlaceOrder', $events);
        $this->assertCount(1, $events['PlaceOrder']);
        $this->assertSame($event, $events['PlaceOrder'][0]);
    }

    public function testTransformAggregatesEventsFromSubResults(): void
    {
        $transformer = new DomainResponseTransformer();

        $event1 = new class {
            public string $type = 'OrderCreated';
        };
        $event2 = new class {
            public string $type = 'StockReserved';
        };

        $main = new ContextResponse('PlaceOrder');
        $main->addEvent($event1);

        $sub = new ContextResponse('CheckInventory');
        $sub->addEvent($event2);

        $main->addResult($sub);

        $response = $transformer->transform($main);
        $events = $response->getEvents();

        $this->assertArrayHasKey('PlaceOrder', $events);
        $this->assertArrayHasKey('CheckInventory', $events);
    }

    public function testTransformAggregatesErrors(): void
    {
        $transformer = new DomainResponseTransformer();

        $result = new ContextResponse('PlaceOrder');
        $result->addError('Stock insufficient');

        $response = $transformer->transform($result);
        $errors = $response->getErrors();

        $this->assertArrayHasKey('PlaceOrder', $errors);
        $this->assertContains('Stock insufficient', $errors['PlaceOrder']);
    }

    public function testTransformAggregatesErrorsFromSubResults(): void
    {
        $transformer = new DomainResponseTransformer();

        $main = new ContextResponse('PlaceOrder');
        $main->addError('Validation failed');

        $sub = new ContextResponse('ChargePayment');
        $sub->addError('Payment declined');

        $main->addResult($sub);

        $response = $transformer->transform($main);
        $errors = $response->getErrors();

        $this->assertArrayHasKey('PlaceOrder', $errors);
        $this->assertArrayHasKey('ChargePayment', $errors);
        $this->assertContains('Validation failed', $errors['PlaceOrder']);
        $this->assertContains('Payment declined', $errors['ChargePayment']);
    }

    public function testTransformExcludesEmptyErrorContexts(): void
    {
        $transformer = new DomainResponseTransformer();

        $main = new ContextResponse('PlaceOrder');
        // No errors

        $sub = new ContextResponse('CheckInventory');
        $sub->addError('Out of stock');

        $main->addResult($sub);

        $response = $transformer->transform($main);
        $errors = $response->getErrors();

        $this->assertArrayNotHasKey('PlaceOrder', $errors);
        $this->assertArrayHasKey('CheckInventory', $errors);
    }

    public function testTransformResolvesSuccessStatusWhenNoErrors(): void
    {
        $transformer = new DomainResponseTransformer();

        $result = new ContextResponse('PlaceOrder');
        $result->setData(['orderId' => 'ORD-123']);

        $response = $transformer->transform($result);

        $this->assertSame(200, $response->getStatus());
    }

    public function testTransformResolvesValidationErrorStatusWhenErrors(): void
    {
        $transformer = new DomainResponseTransformer();

        $result = new ContextResponse('PlaceOrder');
        $result->addError('Validation failed');

        $response = $transformer->transform($result);

        $this->assertSame(400, $response->getStatus());
    }

    public function testTransformResolvesValidationErrorFromSubResultErrors(): void
    {
        $transformer = new DomainResponseTransformer();

        $main = new ContextResponse('PlaceOrder');

        $sub = new ContextResponse('ChargePayment');
        $sub->addError('Payment declined');

        $main->addResult($sub);

        $response = $transformer->transform($main);

        $this->assertSame(400, $response->getStatus());
    }

    public function testTransformAcceptsExplicitStatus(): void
    {
        $transformer = new DomainResponseTransformer();

        $result = new ContextResponse('PlaceOrder');
        $result->setData(['orderId' => 'ORD-123']);

        $response = $transformer->transform($result, ResponseStatus::Created);

        $this->assertSame(201, $response->getStatus());
    }

    public function testTransformExplicitStatusOverridesAutoResolution(): void
    {
        $transformer = new DomainResponseTransformer();

        $result = new ContextResponse('PlaceOrder');
        $result->addError('Some error');

        $response = $transformer->transform($result, ResponseStatus::InternalError);

        $this->assertSame(500, $response->getStatus());
    }

    public function testTransformBuildsMetadata(): void
    {
        $transformer = new DomainResponseTransformer('1.0');

        $result = new ContextResponse('PlaceOrder');

        $response = $transformer->transform($result);
        $metadata = $response->getMetadata();

        $this->assertArrayHasKey('duration', $metadata);
        $this->assertArrayHasKey('contexts', $metadata);
        $this->assertArrayHasKey('timestamp', $metadata);
        $this->assertArrayHasKey('version', $metadata);

        $this->assertIsFloat($metadata['duration']);
        $this->assertContains('PlaceOrder', $metadata['contexts']);
        $this->assertSame('1.0', $metadata['version']);
    }

    public function testTransformCollectsAllContextsInMetadata(): void
    {
        $transformer = new DomainResponseTransformer();

        $main = new ContextResponse('PlaceOrder');

        $sub1 = new ContextResponse('CheckInventory');
        $sub2 = new ContextResponse('ChargePayment');

        $main->addResult($sub1);
        $main->addResult($sub2);

        $response = $transformer->transform($main);
        $contexts = $response->getMetadata()['contexts'];

        $this->assertContains('PlaceOrder', $contexts);
        $this->assertContains('CheckInventory', $contexts);
        $this->assertContains('ChargePayment', $contexts);
        $this->assertCount(3, $contexts);
    }

    public function testTransformDeduplicatesContextsInMetadata(): void
    {
        $transformer = new DomainResponseTransformer();

        $main = new ContextResponse('PlaceOrder');
        $sub = new ContextResponse('PlaceOrder');
        $main->addResult($sub);

        $response = $transformer->transform($main);
        $contexts = $response->getMetadata()['contexts'];

        $this->assertCount(1, $contexts);
    }

    public function testTransformDeeplyNestedResults(): void
    {
        $transformer = new DomainResponseTransformer();

        $level1 = new ContextResponse('Workflow');
        $level2 = new ContextResponse('PlaceOrder');
        $level3 = new ContextResponse('CheckInventory');

        $level3->setData(['stock' => 42]);
        $level3->addEvent(new class {
            public string $type = 'StockChecked';
        });

        $level2->addResult($level3);
        $level1->addResult($level2);

        $response = $transformer->transform($level1);

        $this->assertArrayHasKey('CheckInventory', $response->getData());
        $this->assertSame(42, $response->getData()['CheckInventory']['stock']);
        $this->assertArrayHasKey('CheckInventory', $response->getEvents());
    }

    public function testTransformHandlesCyclicResults(): void
    {
        $transformer = new DomainResponseTransformer();

        $a = new ContextResponse('A');
        $a->addData('key', 'valueA');

        $b = new ContextResponse('B');
        $b->addData('key', 'valueB');

        // Create cycle: A → B → A
        $a->addResult($b);
        $b->addResult($a);

        $response = $transformer->transform($a);

        $this->assertArrayHasKey('A', $response->getData());
        $this->assertArrayHasKey('B', $response->getData());
        $this->assertTrue($response->isSuccess());
    }

    public function testTransformHandlesSelfReference(): void
    {
        $transformer = new DomainResponseTransformer();

        $a = new ContextResponse('Self');
        $a->addData('key', 'value');
        $a->addResult($a);

        $response = $transformer->transform($a);

        $this->assertArrayHasKey('Self', $response->getData());
        $this->assertTrue($response->isSuccess());
    }
}
