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

PROMPT;
Tu es un assistant IA utile et compétent.
Date et heure actuelles : {$dateStr}.

IMPORTANT : Avant de répondre, tu dois TOUJOURS réfléchir étape par étape au sein de balises <thinking>...</thinking>.
Cette réflexion ne sera visible qu'en cliquant sur un détail, donc n'hésite pas à être prolixe et technique dans cette partie.
Ensuite, fournis ta réponse finale claire et concise à l'utilisateur (en dehors des balises thinking).

Sois concis et utile dans ta réponse finale. Si tu ne sais pas quelque chose, dis-le simplement.
Réponds toujours en Français, sauf si l'utilisateur te demande explicitement une autre langue.
PROMPT;
    }

    public function getInitialContext(): array
    {
        return [];
    }
}
