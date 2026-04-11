<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;

/**
 * Dispatché par {@see \ArnaudMoncondhuy\SynapseCore\Agent\Autonomy\AbstractPlannerAgent}
 * quand le planner abandonne sans atteindre son goal.
 *
 * Chantier D — Principe 8. La transparency sidebar écoute cet event
 * (type NDJSON `goal_failed`) et bascule le goal courant en état
 * « échoué » avec un indicateur visuel rouge + la raison de l'abandon.
 *
 * ## Trois cas d'échec distincts
 *
 * 1. `reason: 'max_iterations'` — le planner a épuisé son budget
 *    d'itérations (`maxPlanningIterations`, défaut 3) sans voir
 *    `shouldReplan()` retourner false.
 * 2. `reason: 'execution_failed'` — toutes les tentatives de plan ont
 *    échoué en exécution (exception). Le message porte la dernière
 *    erreur rencontrée.
 * 3. `reason: 'empty_plan'` — le LLM a retourné un plan vide
 *    intentionnellement en disant qu'il n'y a rien à faire pour
 *    atteindre le goal (cas limite, rare mais légal).
 * 4. `reason: 'budget_exceeded'` — une `BudgetExceededException` a
 *    interrompu la boucle avant que le goal soit atteint.
 */
final class SynapseGoalFailedEvent
{
    /**
     * @param array<string, mixed> $totalUsage Usage cumulé au moment de l'abandon
     */
    public function __construct(
        public readonly string $plannerName,
        public readonly Goal $goal,
        public readonly int $iterations,
        public readonly string $reason,
        public readonly ?string $errorMessage = null,
        public readonly array $totalUsage = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'planner_name' => $this->plannerName,
            'goal' => $this->goal->toArray(),
            'iterations' => $this->iterations,
            'reason' => $this->reason,
            'error_message' => $this->errorMessage,
            'total_usage' => $this->totalUsage,
        ];
    }
}
