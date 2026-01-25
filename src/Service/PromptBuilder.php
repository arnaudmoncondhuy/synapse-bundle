<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;

/**
 * Constructeur de Prompts Systèmes.
 *
 * Ce service assemble les différentes couches d'instructions pour former le
 * "System Instruction" final envoyé à Gemini.
 * Il combine :
 * 1. Le Prompt Technique (interne, pour le format de pensée).
 * 2. Le Prompt Système de l'application (via ContextProvider).
 * 3. Le Prompt de la Personnalité sélectionnée (optionnel).
 */
class PromptBuilder
{
    /**
     * Instructions techniques injectées de force pour garantir le bon fonctionnement du bundle.
     * Impose l'utilisation des balises <thinking> pour la chaîne de pensée (CoT).
     */
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
        private PersonaRegistry $personaRegistry,
    ) {
    }

    /**
     * Construit l'instruction système complète.
     *
     * @param string|null $personaKey clé optionnelle pour activer une personnalité spécifique
     *
     * @return string le prompt complet fusionné
     */
    public function buildSystemInstruction(?string $personaKey = null): string
    {
        $basePrompt = $this->contextProvider->getSystemPrompt();
        $finalPrompt = self::TECHNICAL_PROMPT . "\n\n" . $basePrompt;

        if ($personaKey) {
            $personaPrompt = $this->personaRegistry->getSystemPrompt($personaKey);
            if ($personaPrompt) {
                // On ajoute une section claire pour la personnalité pour éviter les conflits de ROLE
                $finalPrompt .= "\n\n### 🎭 PERSONALITY INSTRUCTIONS\n";
                $finalPrompt .= "IMPORTANT: The following personality only applies to your TONE and STYLE of expression.\n";
                $finalPrompt .= "It does NOT affect your reasoning capabilities, logic, or strict adherence to technical constraints.\n\n";
                $finalPrompt .= $personaPrompt;
            }
        }

        return $finalPrompt;
    }
}
