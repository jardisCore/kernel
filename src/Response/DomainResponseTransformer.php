<?php

declare(strict_types=1);

namespace JardisCore\Domain\Response;

use JardisPort\Domain\ContextResultInterface;
use JardisPort\Domain\DomainResponseInterface;

/**
 * Transforms ContextResults into an immutable DomainResponse.
 *
 * Aggregates data, events and errors from a ContextResult tree.
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
        ContextResultInterface $result,
        ?ResponseStatus $status = null
    ): DomainResponseInterface {
        $data = $this->aggregateData($result);
        $events = $this->aggregateEvents($result);
        $errors = $this->aggregateErrors($result);
        $contexts = $this->collectContexts($result);

        $resolvedStatus = $status ?? $this->resolveStatus($errors);

        $metadata = [
            'duration' => round((microtime(true) - $this->startedAt) * 1000, 2),
            'contexts' => $contexts,
            'timestamp' => date('c'),
            'version' => $this->version,
        ];

        return new DomainResponse($resolvedStatus, $data, $events, $errors, $metadata);
    }

    /**
     * Aggregate data from result and all sub-results recursively.
     *
     * @return array<string, array<string, mixed>>
     */
    private function aggregateData(ContextResultInterface $result): array
    {
        $data = $result->getData();

        foreach ($result->getResults() as $subResult) {
            $data = array_merge($data, $this->aggregateData($subResult));
        }

        return $data;
    }

    /**
     * Aggregate events from result and all sub-results recursively.
     *
     * @return array<string, array<int, object>>
     */
    private function aggregateEvents(ContextResultInterface $result): array
    {
        $events = $result->getEvents();

        foreach ($result->getResults() as $subResult) {
            $events = array_merge($events, $this->aggregateEvents($subResult));
        }

        return $events;
    }

    /**
     * Aggregate errors from result and all sub-results recursively.
     *
     * @return array<string, array<int, string>>
     */
    private function aggregateErrors(ContextResultInterface $result): array
    {
        $errors = [];

        $resultErrors = $result->getErrors();
        foreach ($resultErrors as $context => $contextErrors) {
            if (!empty($contextErrors)) {
                $errors[$context] = $contextErrors;
            }
        }

        foreach ($result->getResults() as $subResult) {
            $errors = array_merge($errors, $this->aggregateErrors($subResult));
        }

        return $errors;
    }

    /**
     * Collect all context names from result tree.
     *
     * @return array<int, string>
     */
    private function collectContexts(ContextResultInterface $result): array
    {
        $contexts = array_keys($result->getData());

        foreach ($result->getResults() as $subResult) {
            $contexts = array_merge($contexts, $this->collectContexts($subResult));
        }

        return array_values(array_unique($contexts));
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
