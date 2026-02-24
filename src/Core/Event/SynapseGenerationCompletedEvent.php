<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired WHEN the entire generation process (including all potential tool calls) is finished.
 */
class SynapseGenerationCompletedEvent extends Event
{
    public function __construct(
        private string $fullResponse,
        private array $usage = [],
        private ?string $debugId = null,
    ) {}

    public function getFullResponse(): string
    {
        return $this->fullResponse;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getDebugId(): ?string
    {
        return $this->debugId;
    }
}
