<?php

declare(strict_types=1);

namespace JardisCore\Kernel\Response;

use JardisSupport\Contract\Kernel\ContextResponseInterface;
use JardisSupport\Contract\Kernel\DomainResponseInterface;
use JardisSupport\Contract\Kernel\EventScope;

/**
 * Transforms ContextResponses into an immutable DomainResponse.
 *
 * Aggregates data, events and errors from a ContextResponse tree.
 * Determines the appropriate ResponseStatus from collected errors.
 * Builds metadata (duration, contexts, timestamp, version).
 */
class DomainResponseTransformer
{
    private readonly float $startedAt;

    public function __construct(
        private readonly string $version = ''
    ) {
        $this->startedAt = microtime(true);
    }

    public function transform(
        ContextResponseInterface $result,
        ?ResponseStatus $status = null
    ): DomainResponseInterface {
        $data = $this->aggregateData($result, $this->visited());
        $eventsByScope = $this->aggregateEvents($result);
        $errors = $this->aggregateErrors($result, $this->visited());
        $contexts = $this->collectContexts($result, $this->visited());

        $resolvedStatus = $status ?? $this->resolveStatus($errors);

        $metadata = [
            'duration' => round((microtime(true) - $this->startedAt) * 1000, 2),
            'contexts' => $contexts,
            'timestamp' => date('c'),
            'version' => $this->version,
        ];

        return new DomainResponse($resolvedStatus, $data, [], $errors, $metadata, $eventsByScope);
    }

    /**
     * Aggregate data from result and all sub-results recursively.
     *
     * @param \SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<string, array<string, mixed>>
     */
    private function aggregateData(ContextResponseInterface $result, \SplObjectStorage $visited): array
    {
        $data = $result->getData();

        foreach ($this->subResults($result, $visited) as $subResult) {
            $data = array_merge($data, $this->aggregateData($subResult, $visited));
        }

        return $data;
    }

    /**
     * Aggregate events from the result tree, grouped by scope value, then context.
     *
     * Each scope is walked over its own fresh visited-set; empty scopes are
     * dropped so getEvents(Domain) stays empty until a Domain producer exists.
     *
     * @return array<string, array<string, array<int, object>>>
     */
    private function aggregateEvents(ContextResponseInterface $result): array
    {
        $byScope = [];

        foreach (EventScope::cases() as $scope) {
            $scopeEvents = $this->aggregateEventsForScope($result, $scope, $this->visited());
            if ($scopeEvents !== []) {
                $byScope[$scope->value] = $scopeEvents;
            }
        }

        return $byScope;
    }

    /**
     * Aggregate the events of a single scope from result and all sub-results recursively.
     *
     * @param \SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<string, array<int, object>>
     */
    private function aggregateEventsForScope(
        ContextResponseInterface $result,
        EventScope $scope,
        \SplObjectStorage $visited
    ): array {
        $events = [];

        foreach ($result->getEvents($scope) as $context => $contextEvents) {
            if (!empty($contextEvents)) {
                $events[$context] = $contextEvents;
            }
        }

        foreach ($this->subResults($result, $visited) as $subResult) {
            $events = array_merge($events, $this->aggregateEventsForScope($subResult, $scope, $visited));
        }

        return $events;
    }

    /**
     * Aggregate errors from result and all sub-results recursively.
     *
     * @param \SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<string, array<int, string>>
     */
    private function aggregateErrors(ContextResponseInterface $result, \SplObjectStorage $visited): array
    {
        $errors = [];

        $resultErrors = $result->getErrors();
        foreach ($resultErrors as $context => $contextErrors) {
            if (!empty($contextErrors)) {
                $errors[$context] = $contextErrors;
            }
        }

        foreach ($this->subResults($result, $visited) as $subResult) {
            $errors = array_merge($errors, $this->aggregateErrors($subResult, $visited));
        }

        return $errors;
    }

    /**
     * Collect all context names from result tree.
     *
     * @param \SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<int, string>
     */
    private function collectContexts(ContextResponseInterface $result, \SplObjectStorage $visited): array
    {
        $contexts = array_keys($result->getData());

        foreach ($this->subResults($result, $visited) as $subResult) {
            $contexts = array_merge($contexts, $this->collectContexts($subResult, $visited));
        }

        return array_values(array_unique($contexts));
    }

    /**
     * Returns sub-results, skipping already visited nodes to prevent infinite recursion.
     *
     * @param \SplObjectStorage<ContextResponseInterface, true> $visited
     * @return array<int, ContextResponseInterface>
     */
    private function subResults(ContextResponseInterface $result, \SplObjectStorage $visited): array
    {
        $filtered = [];

        foreach ($result->getResults() as $subResult) {
            if (!$visited->contains($subResult)) {
                $visited->attach($subResult);
                $filtered[] = $subResult;
            }
        }

        return $filtered;
    }

    /**
     * @return \SplObjectStorage<ContextResponseInterface, true>
     */
    private function visited(): \SplObjectStorage
    {
        /** @var \SplObjectStorage<ContextResponseInterface, true> */
        $storage = new \SplObjectStorage();
        return $storage;
    }

    /**
     * Resolve status from errors. No errors = Success, otherwise ValidationError.
     *
     * @param array<string, array<int, string>> $errors
     */
    private function resolveStatus(array $errors): ResponseStatus
    {
        return empty($errors) ? ResponseStatus::Success : ResponseStatus::ValidationError;
    }
}
