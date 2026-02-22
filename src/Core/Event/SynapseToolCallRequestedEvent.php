<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired WHEN the LLM requests a tool/function call.
 *
 * Allows subscribers to:
 * - Resolve and execute the tool
 * - Validate tool arguments
 * - Log tool invocations
 * - Return results to the LLM
 */
class SynapseToolCallRequestedEvent extends Event
{
    /** @var array<array{name: string, args: array}> */
    private array $toolCalls;
    private array $results = [];

    /**
     * @param array<array{name: string, args: array}> $toolCalls
     */
    public function __construct(array $toolCalls)
    {
        $this->toolCalls = $toolCalls;
    }

    /**
     * Get all tool calls requested by the LLM.
     *
     * @return array<array{name: string, args: array}>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Register a tool execution result.
     *
     * @param string $toolName
     * @param mixed  $result
     */
    public function setToolResult(string $toolName, mixed $result): self
    {
        $this->results[$toolName] = $result;
        return $this;
    }

    /**
     * Get all registered tool results.
     *
     * @return array<string, mixed>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get result for a specific tool.
     */
    public function getResult(string $toolName): mixed
    {
        return $this->results[$toolName] ?? null;
    }

    /**
     * Check if all tool calls have results registered.
     */
    public function areAllResultsRegistered(): bool
    {
        return count($this->results) === count($this->toolCalls);
    }
}
