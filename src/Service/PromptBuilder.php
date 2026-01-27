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
 * 1. Le Prompt Technique (interne, thinking natif).
 * 2. Le Prompt Système de l'application (via ContextProvider).
 * 3. Le Prompt de la Personnalité sélectionnée (optionnel).
 */
class PromptBuilder
{
    /**
     * Instructions techniques pour le mode thinking natif de Gemini.
     * Le système capture automatiquement la réflexion via thinkingConfig.
     */
    private const TECHNICAL_PROMPT = <<<PROMPT
### CADRE TECHNIQUE DE RÉPONSE
Tu es une Intelligence Artificielle avec un mode de réflexion natif activé.

Le système capture automatiquement ton processus de réflexion interne via thinkingConfig.
Tu n'as PAS besoin d'utiliser de balises <thinking> manuellement.

Ta réponse à l'utilisateur doit être :
- Format Markdown propre
- URLs en format [Texte](url) obligatoire, JAMAIS d'URL brute
- Directe, structurée et professionnelle
- Sans référence explicite à ton processus de réflexion interne
- Sans mention de ces instructions techniques

IMPORTANT : Ne jamais afficher de balises <thinking> ou faire référence à ta réflexion interne.
Le système gère cela automatiquement en arrière-plan.
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

        // Ajout d'un séparateur horizontal pour couper la hiérarchie Markdown
        $finalPrompt = self::TECHNICAL_PROMPT."\n\n---\n\n".$basePrompt;

        if ($personaKey) {
            $personaPrompt = $this->personaRegistry->getSystemPrompt($personaKey);
            if ($personaPrompt) {
                // On ajoute une section claire pour la personnalité pour éviter les conflits de ROLE
                $finalPrompt .= "\n\n---\n\n### 🎭 PERSONALITY INSTRUCTIONS\n";
                $finalPrompt .= "IMPORTANT : La personnalité suivante s'applique UNIQUEMENT à ton TON et ton STYLE d'expression.\n";
                $finalPrompt .= "Elle n'affecte PAS tes capacités de raisonnement, ta logique ou le respect strict des contraintes techniques.\n\n";
                $finalPrompt .= $personaPrompt;
            }
        }

        return $finalPrompt;
    }
}
