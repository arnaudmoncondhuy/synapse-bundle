<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface for providing context to the AI.
 *
 * Implement this interface to inject custom system prompts and initial context
 * into the Gemini conversation.
 */
interface ContextProviderInterface
{
    /**
     * Returns the system prompt (identity, rules, instructions for the AI).
     */
    public function getSystemPrompt(): string;

    /**
     * Returns initial context to inject into the conversation.
     *
     * This can be used to provide user-specific data, current date, etc.
     *
     * @return array<string, mixed>
     */
    public function getInitialContext(): array;
}
