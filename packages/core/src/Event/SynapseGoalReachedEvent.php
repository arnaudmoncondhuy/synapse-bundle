<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;

/**
 * Dispatché par {@see \ArnaudMoncondhuy\SynapseCore\Agent\Autonomy\AbstractPlannerAgent}
 * quand le planner estime que son goal est atteint et sort de la boucle
 * observe-plan-replan en succès.
 *
 * Chantier D — Principe 8. La transparency sidebar écoute cet event
 * (type NDJSON `goal_reached`) et met à jour la section `goals` pour
 * basculer le goal courant en état « atteint » avec un indicateur
 * visuel vert + le nombre d'itérations nécessaires.
 *
 * ## Quand est-il dispatché ?
 *
 * Précisément au point où `AbstractPlannerAgent::call()` sort de sa
 * boucle `while` avec succès — c'est-à-dire quand `shouldReplan()`
 * retourne `false` après une exécution de plan qui a rempli les
 * `successCriteria` du goal. Les cas d'échec (max iterations, budget
 * exceeded, plan vide) émettent {@see SynapseGoalFailedEvent} à la place.
 *
 * ## Payload
 *
 * - `goal` : le Goal complet avec description, success criteria, budget.
 * - `iterations` : nombre de tours de plan exécutés (1+ pour un succès,
 *   0 étant impossible ici).
 * - `plannerName` : nom de l'agent planner qui a atteint le goal (utile
 *   quand plusieurs planners tournent en parallèle dans une même session).
 * - `totalUsage` : usage cumulé de toutes les itérations (prompt, completion,
 *   total) pour que la sidebar puisse afficher le coût du chemin atteint.
 */
final class SynapseGoalReachedEvent
{
    /**
     * @param array<string, mixed> $totalUsage Usage cumulé (ex: {prompt_tokens, completion_tokens, total_tokens})
     */
    public function __construct(
        public readonly string $plannerName,
        public readonly Goal $goal,
        public readonly int $iterations,
        public readonly array $totalUsage = [],
    ) {
    }

    /**
     * Sérialisation en array pour le streaming NDJSON vers le front.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'planner_name' => $this->plannerName,
            'goal' => $this->goal->toArray(),
            'iterations' => $this->iterations,
            'total_usage' => $this->totalUsage,
        ];
    }
}
