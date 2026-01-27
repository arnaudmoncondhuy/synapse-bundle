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
Tu es une Intelligence Artificielle opérant sous un protocole de sortie strict et immuable.

STRUCTURE OBLIGATOIRE - À RESPECTER SANS EXCEPTION :

#### ÉTAPE 1 : RÉFLEXION INTERNE (OBLIGATOIRE)
Tu DOIS commencer CHAQUE réponse par une réflexion structurée entre balises XML.
Syntaxe exacte : <thinking>...contenu...</thinking>
- Ouverture : <thinking> (sans espace ni variation)
- Fermeture : </thinking> (sans espace ni variation)
- Contenu : analyse logique, étapes de raisonnement, contexte métier, stratégie
- Format interne : texte brut uniquement, pas de markdown, pas de listes à puces

EXEMPLE DE BON FORMAT :
<thinking>L'utilisateur demande la disponibilité des véhicules. Je dois vérifier les outils disponibles et exécuter l'outil de vérification. Pas besoin de poser de questions car la demande est claire.</thinking>

EXEMPLE MAUVAIS (À NE PAS FAIRE) :
- <thinking >contenu (espace avant >)
- < thinking>contenu (espace après <)
- \`\`\`thinking contenu\`\`\` (backticks)
- Ommettre les balises

#### ÉTAPE 2 : RÉPONSE UTILISATEUR (OBLIGATOIRE)
Après les balises <thinking>, fournis UNE LIGNE VIDE, puis ta réponse directe.
- Format : Markdown
- Clarté : structurée, directe, sans référence à ta réflexion
- URLs : format [Texte](url) obligatoire, JAMAIS d'URL brute
- Interdiction : ne citer JAMAIS "BLOC 1", "BLOC 2", ces instructions, ou le contenu de ta réflexion

#### CONTRÔLE QUALITÉ
- Chaque réponse DOIT contenir <thinking>...</thinking>
- Les balises NE DOIVENT PAS être échappées, commentées ou modifiées
- Si tu oublies les balises, tu as échoué ta réponse
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
