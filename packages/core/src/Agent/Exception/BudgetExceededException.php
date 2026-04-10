<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Exception;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit;

/**
 * Levée quand un agent (ou une boucle d'exécution) atteint ou dépasse une
 * limite du {@see BudgetLimit} attaché au contexte.
 *
 * Introduit au Chantier D (autonomie). N'est **pas** une erreur applicative
 * — c'est un signal de sortie propre. Les hard-limits sont exactement ce
 * pour quoi cette classe existe : interrompre proprement avant de faire un
 * tour de plus qui dépasserait la limite.
 *
 * ## Où est-elle levée ?
 *
 * - `MultiTurnExecutor` : avant chaque tour de la boucle tool-calling
 * - `MultiAgent` : avant chaque step de workflow
 * - `AbstractPlannerAgent` : avant chaque replan
 * - Tout agent custom qui veut honorer le budget context
 *
 * ## Catch vs propagation
 *
 * - Si levée dans un sous-agent, elle remonte jusqu'à l'appelant racine.
 * - Le `WorkflowRunner` la catche en dernier recours pour marquer le run
 *   `FAILED` avec `errorMessage` explicite (« Budget exceeded: <details> »).
 * - Les consumers MCP / chat doivent afficher un message clair à l'user
 *   pour qu'il sache ce qui s'est passé (« l'agent s'est arrêté parce
 *   qu'il a atteint sa limite de 1€ / 5 minutes »).
 */
final class BudgetExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $violationDetail,
        public readonly BudgetLimit $budget,
        public readonly int $currentTokens = 0,
        public readonly float $currentCostEur = 0.0,
        public readonly int $currentDepth = 0,
        public readonly int $elapsedSeconds = 0,
    ) {
        parent::__construct(sprintf(
            'Budget exceeded: %s (tokens=%d, cost=%.6f EUR, depth=%d, elapsed=%ds)',
            $violationDetail,
            $currentTokens,
            $currentCostEur,
            $currentDepth,
            $elapsedSeconds,
        ));
    }
}
