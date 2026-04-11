<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\BudgetLimit;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\Goal;

/**
 * Contexte d'exécution transporté entre les appels d'agents.
 *
 * VO immutable porteur des informations runtime qui ne doivent PAS faire partie
 * de l'entrée métier ({@see Input}) : traçabilité arborescente, budget, profondeur
 * de composition, identité de l'appelant.
 *
 * **Contrainte forte** : tous les champs sont purement scalaires (ou des tableaux
 * de scalaires) pour permettre une sérialisation Messenger native. Ne jamais y
 * placer d'entités Doctrine, de services ou d'objets métier complexes.
 *
 * Ce VO n'existe pas dans `symfony/ai` — c'est une extension Synapse qui sera
 * transportée via la clé `$options['context']` de {@see AgentInterface::call()},
 * pour rester compatible mot-pour-mot avec la signature de leur interface.
 *
 * @author Synapse Bundle
 */
final class AgentContext
{
    /**
     * Profondeur maximale par défaut d'agent-sur-agent.
     *
     * Sert de fallback si {@see self::$maxDepth} n'est pas surchargé via la config
     * `synapse.agents.max_depth`. Garde-fou #5 documenté dans CRITICAL_GUARDRAILS.md.
     */
    public const DEFAULT_MAX_DEPTH = 2;

    /**
     * @param string $requestId UUID / identifiant unique de cette exécution (propagé dans les logs)
     * @param string|null $userId Identifiant de l'utilisateur appelant (null = système)
     * @param string|null $parentRunId `agent_run_id` du parent si cet appel est imbriqué
     * @param string|null $workflowRunId UUID du workflow englobant si on est dans un workflow
     * @param int $depth Profondeur courante (0 = appel racine)
     * @param int $maxDepth Profondeur maximale autorisée
     * @param int|null $budgetTokensRemaining (@deprecated) Budget tokens restant hérité — conservé pour BC mais remplacé par $budget (Chantier D)
     * @param string $origin Origine de l'appel : 'direct' | 'code' | 'config' | 'ephemeral' | 'workflow'
     * @param BudgetLimit|null $budget Limites de budget (Chantier D). null = pas de budget explicite, l'ancien `budgetTokensRemaining` prend le relais s'il est renseigné.
     * @param Goal|null $goal Objectif poursuivi par l'agent autonome (Chantier D). null pour les agents conversationnels/réactifs classiques. Porté pour que les sous-agents appelés dans une boucle planner puissent y accéder.
     * @param \DateTimeImmutable|null $startedAt Timestamp de début de run, propagé pour calculer `elapsedSeconds` à chaque check budget. Défaut : now au moment du root context.
     */
    public function __construct(
        private readonly string $requestId,
        private readonly ?string $userId = null,
        private readonly ?string $parentRunId = null,
        private readonly ?string $workflowRunId = null,
        private readonly int $depth = 0,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
        private readonly ?int $budgetTokensRemaining = null,
        private readonly string $origin = 'direct',
        private readonly ?BudgetLimit $budget = null,
        private readonly ?Goal $goal = null,
        private readonly ?\DateTimeImmutable $startedAt = null,
    ) {
    }

    /**
     * Crée un contexte racine (depth=0, pas de parent) avec un requestId généré.
     */
    public static function root(
        ?string $userId = null,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        ?int $budgetTokensRemaining = null,
        string $origin = 'direct',
        ?BudgetLimit $budget = null,
        ?Goal $goal = null,
    ): self {
        return new self(
            requestId: self::generateRequestId(),
            userId: $userId,
            depth: 0,
            maxDepth: $maxDepth,
            budgetTokensRemaining: $budgetTokensRemaining,
            origin: $origin,
            budget: $budget,
            goal: $goal,
            startedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Crée un contexte enfant pour un appel imbriqué (un agent en invoque un autre).
     *
     * Incrémente la profondeur, préserve le user et le workflow englobant,
     * remet à jour l'origine selon le type d'agent résolu.
     *
     * @param string $parentRunId `agent_run_id` du parent (obligatoire pour l'arborescence)
     */
    public function createChild(string $parentRunId, string $childOrigin = 'code'): self
    {
        return new self(
            requestId: self::generateRequestId(),
            userId: $this->userId,
            parentRunId: $parentRunId,
            workflowRunId: $this->workflowRunId,
            depth: $this->depth + 1,
            maxDepth: $this->maxDepth,
            budgetTokensRemaining: $this->budgetTokensRemaining,
            origin: $childOrigin,
            budget: $this->budget,
            goal: $this->goal,
            startedAt: $this->startedAt,
        );
    }

    /**
     * Retourne une copie immutable du contexte avec `workflowRunId` renseigné.
     *
     * Utilisé par le futur moteur `MultiAgent` (Phase 8) au démarrage d'un workflow :
     * il propage le `workflowRunId` fraîchement généré dans le contexte avant de
     * déléguer à chaque agent d'étape. Tous les {@see SynapseDebugLog} produits par
     * ces appels verront alors leur champ `workflow_run_id` renseigné, permettant
     * de reconstituer l'arbre complet d'un run.
     *
     * Ne peut pas être utilisée pour *désassigner* un workflow — pour cela, créer
     * un nouveau contexte racine.
     */
    public function withWorkflowRunId(string $workflowRunId): self
    {
        return new self(
            requestId: $this->requestId,
            userId: $this->userId,
            parentRunId: $this->parentRunId,
            workflowRunId: $workflowRunId,
            depth: $this->depth,
            maxDepth: $this->maxDepth,
            budgetTokensRemaining: $this->budgetTokensRemaining,
            origin: $this->origin,
            budget: $this->budget,
            goal: $this->goal,
            startedAt: $this->startedAt,
        );
    }

    /**
     * Retourne une copie avec un `BudgetLimit` appliqué. Utilisé typiquement
     * par un {@see Autonomy\AbstractPlannerAgent}
     * qui démarre un run autonome avec des limites explicites.
     */
    public function withBudget(BudgetLimit $budget): self
    {
        return new self(
            requestId: $this->requestId,
            userId: $this->userId,
            parentRunId: $this->parentRunId,
            workflowRunId: $this->workflowRunId,
            depth: $this->depth,
            maxDepth: $this->maxDepth,
            budgetTokensRemaining: $this->budgetTokensRemaining,
            origin: $this->origin,
            budget: $budget,
            goal: $this->goal,
            startedAt: $this->startedAt,
        );
    }

    /**
     * Retourne une copie avec un `Goal` attaché. Utilisé par les planners
     * pour que les sous-agents appelés connaissent l'objectif général du run.
     */
    public function withGoal(Goal $goal): self
    {
        return new self(
            requestId: $this->requestId,
            userId: $this->userId,
            parentRunId: $this->parentRunId,
            workflowRunId: $this->workflowRunId,
            depth: $this->depth,
            maxDepth: $this->maxDepth,
            budgetTokensRemaining: $this->budgetTokensRemaining,
            origin: $this->origin,
            budget: $this->budget,
            goal: $goal,
            startedAt: $this->startedAt,
        );
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getParentRunId(): ?string
    {
        return $this->parentRunId;
    }

    public function getWorkflowRunId(): ?string
    {
        return $this->workflowRunId;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getBudgetTokensRemaining(): ?int
    {
        return $this->budgetTokensRemaining;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getBudget(): ?BudgetLimit
    {
        return $this->budget;
    }

    public function getGoal(): ?Goal
    {
        return $this->goal;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * Durée écoulée depuis le début du run racine (secondes). 0 si startedAt
     * est null (contextes créés sans passer par `root()`).
     */
    public function getElapsedSeconds(): int
    {
        if (null === $this->startedAt) {
            return 0;
        }

        return (new \DateTimeImmutable())->getTimestamp() - $this->startedAt->getTimestamp();
    }

    public function hasParent(): bool
    {
        return null !== $this->parentRunId;
    }

    public function isDepthExceeded(): bool
    {
        return $this->depth >= $this->maxDepth;
    }

    private static function generateRequestId(): string
    {
        // Préférence pour uuid natif PHP 8, fallback sur une version random_bytes.
        if (function_exists('uuid_create')) {
            /** @var string $uuid */
            $uuid = uuid_create();

            return $uuid;
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
