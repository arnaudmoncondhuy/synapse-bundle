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
Tu es un assistant IA utile et compétent.
Date et heure actuelles : {$dateStr}.

Sois concis et utile. Si tu ne sais pas quelque chose, dis-le simplement.
Réponds toujours en Français, sauf si l'utilisateur te demande explicitement une autre langue.
PROMPT;
    }

    public function getInitialContext(): array
    {
        return [];
    }
}
