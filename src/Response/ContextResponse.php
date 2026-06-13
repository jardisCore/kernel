<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Response;

use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;

/**
 * Context Response for transporting results through bounded context chains.
 *
 * Supports nested results for BC chains:
 * Sales BC → Inventory BC → Warehouse BC
 *
 * Each result can collect:
 * - Data (results)
 * - Events (domain events)
 * - Errors (validation/business errors)
 * - Sub-Results (nested BC calls)
 *
 * All getter methods return context-keyed arrays for traceability.
 * Mutable accumulator object passed through BC chains.
 */
class ContextResponse implements ContextResponseInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, array<int, object>> Events grouped by EventScope value */
    private array $events = [];

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, ContextResponseInterface> */
    private array $results = [];

    public function __construct(
        private readonly string $context
    ) {
    }

    protected function getContext(): string
    {
        return $this->context;
    }

    /**
     * Add a single data entry.
     */
    public function addData(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Set all data at once (replaces existing data).
     *
     * @param array<string, mixed> $data
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get data from this result, keyed by context name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getData(): array
    {
        return [$this->context => $this->data];
    }

    /**
     * Add an event, classified by its publication scope.
     */
    public function addEvent(object $event, EventScope $scope = EventScope::Internal): self
    {
        $this->events[$scope->value][] = $event;

        return $this;
    }

    /**
     * Get events from this result, keyed by context name.
     *
     * Without a scope, returns all events (insertion order within each scope,
     * structurally unchanged from the single-bucket behaviour). With a scope,
     * returns only the events of that scope.
     *
     * @return array<string, array<int, object>>
     */
    public function getEvents(?EventScope $scope = null): array
    {
        if ($scope !== null) {
            return [$this->context => $this->events[$scope->value] ?? []];
        }

        $flat = [];
        foreach ($this->events as $scopedEvents) {
            foreach ($scopedEvents as $event) {
                $flat[] = $event;
            }
        }

        return [$this->context => $flat];
    }

    /**
     * Add an error message.
     */
    public function addError(string $message): self
    {
        $this->errors[] = $message;

        return $this;
    }

    /**
     * Get errors from this result, keyed by context name.
     *
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return [$this->context => $this->errors];
    }

    /**
     * Add a sub-result from a nested BC call.
     */
    public function addResult(ContextResponseInterface $result): self
    {
        $this->results[] = $result;

        return $this;
    }

    /**
     * Get direct sub-results.
     *
     * @return array<int, ContextResponseInterface>
     */
    public function getResults(): array
    {
        return $this->results;
    }
}
