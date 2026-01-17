<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Impl;

use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;

/**
 * Default (minimal) context provider.
 *
 * Override this in your application to provide custom system prompts.
 */
class DefaultContextProvider implements ContextProviderInterface
{
    public function getSystemPrompt(): string
    {
        $now = new \DateTimeImmutable('now');
        $dateStr = $now->format('d/m/Y H:i');

        return <<<PROMPT
You are a helpful AI assistant.
Current date and time: {$dateStr}.

Be concise and helpful. If you don't know something, say so.
PROMPT;
    }

    public function getInitialContext(): array
    {
        return [];
    }
}
