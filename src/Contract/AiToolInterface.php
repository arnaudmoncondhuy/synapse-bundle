<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface for AI Tools (Function Calling).
 *
 * Implement this interface in your application to create custom tools
 * that Gemini can call during a conversation.
 */
interface AiToolInterface
{
    /**
     * Returns the unique name of the tool (used by Gemini to call it).
     */
    public function getName(): string;

    /**
     * Returns a description of what the tool does (helps Gemini decide when to use it).
     */
    public function getDescription(): string;

    /**
     * Returns the JSON Schema for the tool's input parameters.
     *
     * @return array{type: string, properties: array<string, mixed>, required?: string[]}
     */
    public function getInputSchema(): array;

    /**
     * Executes the tool with the given parameters.
     *
     * @param array<string, mixed> $parameters The parameters passed by Gemini.
     * @return mixed The result to send back to Gemini.
     */
    public function execute(array $parameters): mixed;
}
