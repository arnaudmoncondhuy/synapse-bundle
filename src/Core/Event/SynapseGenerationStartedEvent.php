<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired WHEN the LLM generation starts (before any API call).
 */
class SynapseGenerationStartedEvent extends Event
{
    public function __construct(
        private string $message,
        private array $options = [],
    ) {}

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
