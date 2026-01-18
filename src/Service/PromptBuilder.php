<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;

/**
 * Assembles the final system prompt by combining:
 * 1. Technical Prompt (Bundle-enforced rules like <thinking>)
 * 2. System Prompt (Application context)
 */
class PromptBuilder
{
    private const TECHNICAL_PROMPT = <<<PROMPT
### 🧠 CERVEAU ANALYTIQUE (OBLIGATOIRE)
Avant de répondre, tu DOIS analyser la situation dans un bloc `<thinking>`.

**FORMAT STRICT :**
- Un SEUL bloc `<thinking>` par réponse
- Pas de backticks (```) autour du bloc, ni ailleurs dans la réponse.
- Pas de formatage markdown dans les balises
- Format exact : `<thinking>ton analyse ici</thinking>`

Ensuite, fournis ta réponse finale claire et concise à l'utilisateur (en dehors des balises thinking).
PROMPT;

    public function __construct(
        private ContextProviderInterface $contextProvider,
    ) {
    }

    public function buildSystemInstruction(): string
    {
        $appSystemPrompt = $this->contextProvider->getSystemPrompt();

        return self::TECHNICAL_PROMPT . "\n\n" . $appSystemPrompt;
    }
}
