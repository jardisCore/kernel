<?php

declare(strict_types=1);

namespace JardisCore\Domain\Response;

use JardisPort\Domain\DomainResponseInterface;

/**
 * Immutable Domain Response.
 *
 * Final response from a domain operation, built by the DomainResponseTransformer.
 * Contains aggregated data, events, errors and metadata from all ContextResults.
 */
readonly class DomainResponse implements DomainResponseInterface
{
    /**
     * @param ResponseStatus $status Result status
     * @param array<string, array<string, mixed>> $data Aggregated data, keyed by context
     * @param array<string, array<int, object>> $events All domain events, keyed by context
     * @param array<string, array<int, string>> $errors All errors, keyed by context
     * @param array<string, mixed> $metadata Response metadata
     */
    public function __construct(
        private ResponseStatus $status,
        private array $data,
        private array $events,
        private array $errors,
        private array $metadata
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === ResponseStatus::Success
            || $this->status === ResponseStatus::Created
            || $this->status === ResponseStatus::NoContent;
    }

    public function getStatus(): int
    {
        return $this->status->value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, array<int, object>>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
