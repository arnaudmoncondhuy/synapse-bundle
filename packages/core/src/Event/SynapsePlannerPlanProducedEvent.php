<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Plan;

/**
 * Dispatché par {@see \ArnaudMoncondhuy\SynapseCore\Agent\Autonomy\AbstractPlannerAgent}
 * quand un plan vient d'être produit par le LLM et validé.
 *
 * Porte le plan structuré complet (reasoning + steps + rationales) ainsi
 * que le goal poursuivi, pour que les consumers UI puissent afficher une
 * section dédiée dans la transparency sidebar du chat :
 *
 *   - section `plan` : affiche le reasoning + la liste des steps avec leur
 *     rationale individuelle, au moment où le planner termine son appel LLM
 *     et juste avant que WorkflowRunner n'enchaîne l'exécution
 *
 * Principe 8 du plan v2 : tout event back doit avoir son rendu front dans
 * la transparency sidebar — cet event est livré conjointement avec l'ajout
 * de sa section JS correspondante pour honorer ce principe.
 *
 * ## Cycle de vie dans la boucle autonome (phase 3+)
 *
 * Quand la boucle observe-plan-replan sera livrée, cet event sera dispatché
 * **une fois par iteration de planification** avec `$iteration` incrémenté.
 * Le front peut alors empiler les plans dans l'historique de la section
 * `plan` pour montrer l'évolution du raisonnement du planner.
 */
final class SynapsePlannerPlanProducedEvent
{
    public function __construct(
        public readonly string $plannerName,
        public readonly Goal $goal,
        public readonly Plan $plan,
        public readonly ?string $workflowRunId = null,
        public readonly ?string $ephemeralWorkflowKey = null,
    ) {
    }

    /**
     * Sérialisation en array pour le streaming NDJSON vers le front.
     *
     * Aligne les clés sur ce que le listener JS
     * {@see synapse_chat_controller.js:renderPlannerPlan()} attend.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'planner_name' => $this->plannerName,
            'workflow_run_id' => $this->workflowRunId,
            'ephemeral_workflow_key' => $this->ephemeralWorkflowKey,
            'goal' => [
                'description' => $this->goal->description,
                'success_criteria' => $this->goal->successCriteria,
            ],
            'plan' => [
                'iteration' => $this->plan->iteration,
                'reasoning' => $this->plan->reasoning,
                'steps' => array_map(
                    /** @param array{name: string, agent_name: string, rationale?: string} $step */
                    static fn (array $step): array => [
                        'name' => $step['name'],
                        'agent_name' => $step['agent_name'],
                        'rationale' => $step['rationale'] ?? '',
                    ],
                    $this->plan->steps,
                ),
                'outputs' => $this->plan->outputs,
            ],
        ];
    }
}
