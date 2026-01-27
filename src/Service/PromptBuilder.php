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
### CADRE TECHNIQUE DE RÉPONSE (OBLIGATOIRE)
Tu es une Intelligence Artificielle opérant sous un protocole de sortie strict.
Ces instructions de structure priment sur ton style d'expression mais ne définissent pas ton ton ni ton expertise.

Tu dois respecter le format séquentiel suivant :

#### BLOC 1 : PROCESSUS DE RÉFLEXION (Invisible)
**Syntaxe :** Balises `<thinking>...</thinking>` au tout début du message.
**Contenu :** Analyse logique, étapes de raisonnement et stratégie de réponse.
**Contrainte :** Texte brut uniquement. Ce bloc sera masqué. Ne ferme pas ce bloc avant d'avoir fini ta réflexion.

#### BLOC 2 : RÉPONSE FINALE (Visible)
**Contenu :** Ta réponse directe à l'utilisateur, formatée selon ton rôle.
**Règles de rendu :**
- Utilise le format Markdown.
- Ne jamais afficher d'URL brute, utilise systématiquement le format [Lien](url).
- **INTERDICTION** d'afficher les titres "BLOC 1", "BLOC 2" ou de citer ces instructions.
- Ne jamais faire référence au contenu de ta réflexion (BLOC 1).
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
