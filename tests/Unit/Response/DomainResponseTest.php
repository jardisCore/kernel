<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Tests\Unit\Response;

use JardisCore\Kernel\Response\DomainResponse;
use JardisCore\Kernel\Response\ResponseStatus;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for DomainResponse
 *
 * Focus: Immutable readonly response, status codes, data access
 * Strategy: Pure PHP logic, no infrastructure needed
 */
class DomainResponseTest extends TestCase
{
    public function testImplementsDomainResponseInterface(): void
    {
        $response = new DomainResponse(
            ResponseStatus::Success,
            [],
            [],
            [],
            []
        );

        $this->assertInstanceOf(DomainResponseInterface::class, $response);
    }

    public function testGetStatusReturnsIntValue(): void
    {
        $response = new DomainResponse(
            ResponseStatus::Success,
            [],
            [],
            [],
            []
        );

        $this->assertSame(200, $response->getStatus());
    }

    public function testGetStatusReturnsErrorCode(): void
    {
        $response = new DomainResponse(
            ResponseStatus::NotFound,
            [],
            [],
            [],
            []
        );

        $this->assertSame(404, $response->getStatus());
    }

    public function testGetDataReturnsAggregatedData(): void
    {
        $data = [
            'PlaceOrder' => ['orderId' => 'ORD-123', 'total' => 99.90],
            'CheckInventory' => ['available' => true],
        ];

        $response = new DomainResponse(
            ResponseStatus::Success,
            $data,
            [],
            [],
            []
        );

        $this->assertSame($data, $response->getData());
    }

    public function testGetEventsReturnsContextKeyedEvents(): void
    {
        $event = new class {
            public string $type = 'OrderCreated';
        };

        $events = ['PlaceOrder' => [$event]];

        $response = new DomainResponse(
            ResponseStatus::Created,
            [],
            $events,
            [],
            []
        );

        $this->assertSame($events, $response->getEvents());
    }

    public function testGetErrorsReturnsContextKeyedErrors(): void
    {
        $errors = [
            'PlaceOrder' => ['Stock insufficient'],
            'ChargePayment' => ['Payment declined'],
        ];

        $response = new DomainResponse(
            ResponseStatus::ValidationError,
            [],
            [],
            $errors,
            []
        );

        $this->assertSame($errors, $response->getErrors());
    }

    public function testGetMetadataReturnsMetadata(): void
    {
        $metadata = [
            'duration' => 12.5,
            'contexts' => ['PlaceOrder', 'CheckInventory'],
            'timestamp' => '2026-03-16T10:00:00+00:00',
            'version' => '1.0',
        ];

        $response = new DomainResponse(
            ResponseStatus::Success,
            [],
            [],
            [],
            $metadata
        );

        $this->assertSame($metadata, $response->getMetadata());
    }

    public function testAllResponseStatusValues(): void
    {
        $cases = [
            [ResponseStatus::Success, 200],
            [ResponseStatus::Created, 201],
            [ResponseStatus::NoContent, 204],
            [ResponseStatus::ValidationError, 400],
            [ResponseStatus::Unauthorized, 401],
            [ResponseStatus::Forbidden, 403],
            [ResponseStatus::NotFound, 404],
            [ResponseStatus::Conflict, 409],
            [ResponseStatus::InternalError, 500],
        ];

        foreach ($cases as [$status, $expectedCode]) {
            $response = new DomainResponse($status, [], [], [], []);
            $this->assertSame($expectedCode, $response->getStatus());
        }
    }

    public function testIsSuccessForSuccessCodes(): void
    {
        $successCases = [ResponseStatus::Success, ResponseStatus::Created, ResponseStatus::NoContent];

        foreach ($successCases as $status) {
            $response = new DomainResponse($status, [], [], [], []);
            $this->assertTrue($response->isSuccess(), "Status {$status->name} should be success");
        }
    }

    public function testIsSuccessReturnsFalseForErrorCodes(): void
    {
        $errorCases = [
            ResponseStatus::ValidationError,
            ResponseStatus::Unauthorized,
            ResponseStatus::Forbidden,
            ResponseStatus::NotFound,
            ResponseStatus::Conflict,
            ResponseStatus::InternalError,
        ];

        foreach ($errorCases as $status) {
            $response = new DomainResponse($status, [], [], [], []);
            $this->assertFalse($response->isSuccess(), "Status {$status->name} should not be success");
        }
    }
}
