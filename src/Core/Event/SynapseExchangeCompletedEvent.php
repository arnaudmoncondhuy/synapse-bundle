<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired AFTER the multi-turn loop completes.
 *
 * Allows subscribers to:
 * - Finalize debug logging
 * - Clean up resources
 * - Publish metrics
 */
class SynapseExchangeCompletedEvent extends Event
{
    public function __construct(
        private string $debugId,
        private string $model,
        private string $provider,
        private array $usage = [],
        private array $safety = [],
        private bool $debugMode = false,
        private array $rawData = [],
    ) {
    }

    public function getDebugId(): string
    {
        return $this->debugId;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getSafety(): array
    {
        return $this->safety;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    public function getRawData(): array
    {
        return $this->rawData;
    }
}
