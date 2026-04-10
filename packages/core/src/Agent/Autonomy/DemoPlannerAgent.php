<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Autonomy;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;

/**
 * Planificateur autonome de démonstration (Chantier D phase 1).
 *
 * Prend un objectif en langage naturel (via `Input::ofMessage()` ou via
 * `$structured['goal']`) et produit un plan exécutable à partir des agents
 * disponibles (ceux implémentant {@see \ArnaudMoncondhuy\SynapseCore\Contract\CallableByAgentsInterface}).
 *
 * Utilisé pour valider le flow Goal → Plan → PlannerAgent → (phase 2 : exécution)
 * avant de déployer la boucle complète observe-replan dans des agents métier.
 *
 * ## Activation
 *
 * Cet agent est automatiquement enregistré comme service car il hérite de
 * `AbstractAgent` qui implémente `AgentInterface` — auto-tag `synapse.agent`.
 * Il apparaît dans `list_agents` MCP et peut être testé via `run_agent_test`.
 *
 * **Nécessite** `synapse.governance.architect_preset_key` (réutilisé ici
 * comme « preset supportant response_schema ») à être configuré côté hôte,
 * sinon le planner échoue à produire un JSON structuré.
 *
 * ## Exemple MCP
 *
 * ```json
 * {
 *   "name": "run_agent_test",
 *   "arguments": {
 *     "agentKey": "demo_planner",
 *     "input": "Trouve un fait intéressant sur les cerisiers en fleurs et rédige une courte phrase poétique à leur sujet."
 *   }
 * }
 * ```
 *
 * Retour attendu : un Plan à 1-2 steps (probablement un seul step appelant
 * `redacteur` puisque c'est le seul agent générique callable disponible en
 * début de roadmap). Au fur et à mesure que d'autres agents implémentent
 * `CallableByAgentsInterface`, le planner produira des plans plus riches.
 */
final class DemoPlannerAgent extends AbstractPlannerAgent
{
    public function getName(): string
    {
        return 'demo_planner';
    }

    public function getLabel(): string
    {
        return 'Planificateur de démo';
    }

    public function getDescription(): string
    {
        return 'Agent de démonstration du pattern planner autonome (Chantier D). Prend un objectif libre et produit un plan structuré exécutable à partir des agents callable disponibles.';
    }

    public function getPresetKey(): ?string
    {
        // Réutilise le preset architecte (déjà configuré pour les structured outputs
        // dans le Chantier C). Si le host utilise un preset différent, il peut
        // override en sous-classant cet agent.
        return null; // Laisse le pipeline utiliser le preset actif + forcer response_format via askOptions
    }

    public function getEmoji(): string
    {
        return '🗺️';
    }

    protected function buildInitialGoal(Input $input): Goal
    {
        $message = trim($input->getMessage());
        if ('' === $message) {
            return Goal::of('Démontrer le flow planner (aucun objectif fourni — retour attendu : un plan trivial)');
        }

        return Goal::of($message);
    }

    protected function buildExtraSystemPromptSection(): string
    {
        return <<<'PROMPT'
Tu es un planificateur de démonstration. Ton objectif est de montrer le pattern planner autonome
en produisant un plan clair et minimal à partir de l'objectif fourni.

Si tu n'as qu'un seul agent callable à disposition, produis un plan à une seule étape. C'est
normal et valide — le plan est un artefact inspectable par l'utilisateur, pas un exercice
de complexité artificielle.
PROMPT;
    }
}
