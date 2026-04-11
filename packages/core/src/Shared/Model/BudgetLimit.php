<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value object immutable représentant les limites de budget d'un agent
 * ou d'un workflow en cours d'exécution.
 *
 * Introduit au Chantier B (persistance coûts) en préparation du Chantier D
 * (autonomie agent). Porté via {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext}
 * à travers toute la chaîne d'exécution. Vérifié à chaque tour par
 * {@see \ArnaudMoncondhuy\SynapseCore\Engine\MultiTurnExecutor} (Chantier D)
 * et à la fin de chaque step par {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\MultiAgent}
 * (Chantier D).
 *
 * ## Sémantique des limites
 *
 * - Toutes les limites sont **optionnelles** (null = pas de limite sur cette
 *   dimension). Un `BudgetLimit` avec tous les champs null est équivalent à
 *   "pas de budget configuré".
 *
 * - Les limites sont **hard** : dès qu'une limite est atteinte ou dépassée,
 *   l'exécution est interrompue et une exception `BudgetExceededException`
 *   est levée. Pas de soft-warn en première approximation — l'utilisateur
 *   qui veut du soft-warn doit se brancher sur les events budget (à venir
 *   Chantier D).
 *
 * - `maxCostEur` est en **devise de référence** (voir
 *   `synapse.token_tracking.reference_currency` qui vaut EUR par défaut).
 *   Cohérent avec la colonne `SynapseDebugLog::$cost` livrée au Chantier B.
 *
 * ## Exemples
 *
 * ```php
 * // Agent autonome borné à 1€ et 5 minutes max
 * $budget = BudgetLimit::withCostAndDuration(1.0, 300);
 *
 * // Workflow production borné à 50000 tokens et depth max 3
 * $budget = new BudgetLimit(maxTokens: 50000, maxDepth: 3);
 *
 * // Pas de limite (à éviter en production avec des agents autonomes)
 * $budget = BudgetLimit::unlimited();
 * ```
 */
final class BudgetLimit
{
    public function __construct(
        public readonly ?int $maxTokens = null,
        public readonly ?float $maxCostEur = null,
        public readonly ?int $maxDepth = null,
        public readonly ?int $maxDurationSeconds = null,
        public readonly ?int $maxPlanningIterations = null,
    ) {
    }

    /**
     * Aucune limite — uniquement pour les tests et les cas de développement.
     * Ne pas utiliser en production avec des agents autonomes (Chantier D).
     */
    public static function unlimited(): self
    {
        return new self();
    }

    /**
     * Limite combinée coût + durée — la plus courante pour un agent autonome.
     */
    public static function withCostAndDuration(float $maxCostEur, int $maxDurationSeconds): self
    {
        return new self(maxCostEur: $maxCostEur, maxDurationSeconds: $maxDurationSeconds);
    }

    /**
     * Vérifie si les consommations courantes dépassent une des limites.
     * Retourne le nom de la première limite dépassée (utile pour message
     * d'erreur) ou null si tout va bien.
     */
    public function firstViolation(
        int $currentTokens = 0,
        float $currentCostEur = 0.0,
        int $currentDepth = 0,
        int $elapsedSeconds = 0,
        int $planningIterations = 0,
    ): ?string {
        if (null !== $this->maxTokens && $currentTokens >= $this->maxTokens) {
            return sprintf('maxTokens (%d reached for limit %d)', $currentTokens, $this->maxTokens);
        }
        if (null !== $this->maxCostEur && $currentCostEur >= $this->maxCostEur) {
            return sprintf('maxCostEur (%.4f reached for limit %.4f)', $currentCostEur, $this->maxCostEur);
        }
        if (null !== $this->maxDepth && $currentDepth >= $this->maxDepth) {
            return sprintf('maxDepth (%d reached for limit %d)', $currentDepth, $this->maxDepth);
        }
        if (null !== $this->maxDurationSeconds && $elapsedSeconds >= $this->maxDurationSeconds) {
            return sprintf('maxDurationSeconds (%ds reached for limit %ds)', $elapsedSeconds, $this->maxDurationSeconds);
        }
        if (null !== $this->maxPlanningIterations && $planningIterations >= $this->maxPlanningIterations) {
            return sprintf('maxPlanningIterations (%d reached for limit %d)', $planningIterations, $this->maxPlanningIterations);
        }

        return null;
    }

    /**
     * @return array<string, float|int|null>
     */
    public function toArray(): array
    {
        return [
            'max_tokens' => $this->maxTokens,
            'max_cost_eur' => $this->maxCostEur,
            'max_depth' => $this->maxDepth,
            'max_duration_seconds' => $this->maxDurationSeconds,
            'max_planning_iterations' => $this->maxPlanningIterations,
        ];
    }
}
