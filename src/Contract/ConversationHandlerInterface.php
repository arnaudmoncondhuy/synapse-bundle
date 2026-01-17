<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface for handling conversation history.
 *
 * Implement this interface to customize how the conversation history is stored
 * (e.g., in session, database, Redis, etc.).
 */
interface ConversationHandlerInterface
{
    /**
     * Loads the conversation history.
     *
     * @return array<int, array{role: string, parts: array<int, array<string, mixed>>}>
     */
    public function loadHistory(): array;

    /**
     * Saves the conversation history.
     *
     * @param array<int, array{role: string, parts: array<int, array<string, mixed>>}> $history
     */
    public function saveHistory(array $history): void;

    /**
     * Clears the conversation history (starts a new conversation).
     */
    public function clearHistory(): void;
}
