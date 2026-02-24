<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired WHEN a tool call has been completed and its result is available.
 */
class SynapseToolCallCompletedEvent extends Event
{
    public function __construct(
        private string $toolName,
        private mixed $result,
        private array $toolCallData = [],
    ) {}

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getToolCallData(): array
    {
        return $this->toolCallData;
    }
}
