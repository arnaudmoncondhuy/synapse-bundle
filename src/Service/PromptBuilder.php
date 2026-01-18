<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;

class PromptBuilder
{
    public function __construct(
        private ContextProviderInterface $contextProvider,
        private PersonaRegistry $personaRegistry,
    ) {
    }

    public function buildSystemInstruction(?string $personaKey = null): string
    {
        $basePrompt = $this->contextProvider->getSystemPrompt();

        if ($personaKey) {
            $personaPrompt = $this->personaRegistry->getSystemPrompt($personaKey);
            if ($personaPrompt) {
                return $basePrompt . "\n\n" . $personaPrompt;
            }
        }

        return $basePrompt;
    }
}
