<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired EACH TIME a chunk is received from the LLM.
 *
 * Allows subscribers to:
 * - Log token data
 * - Stream tokens to frontend (via WebSocket, SSE, etc.)
 * - Detect blocking/safety issues
 * - Accumulate response data
 */
class SynapseChunkReceivedEvent extends Event
{
    private int $turn;
    private ?array $rawChunk = null;

    public function __construct(
        private array $chunk,
        int $turn = 0,
        ?array $rawChunk = null,
    ) {
        $this->turn = $turn;
        $this->rawChunk = $rawChunk;
    }

    public function getChunk(): array
    {
        return $this->chunk;
    }

    public function getText(): ?string
    {
        return $this->chunk['text'] ?? null;
    }

    public function getThinking(): ?string
    {
        return $this->chunk['thinking'] ?? null;
    }

    public function getFunctionCalls(): array
    {
        return $this->chunk['function_calls'] ?? [];
    }

    public function getUsage(): array
    {
        return $this->chunk['usage'] ?? [];
    }

    public function getSafetyRatings(): array
    {
        return $this->chunk['safety_ratings'] ?? [];
    }

    public function isBlocked(): bool
    {
        return $this->chunk['blocked'] ?? false;
    }

    public function getBlockedCategory(): ?string
    {
        return $this->chunk['blocked_category'] ?? null;
    }

    public function getTurn(): int
    {
        return $this->turn;
    }

    public function getRawChunk(): ?array
    {
        return $this->rawChunk;
    }
}
