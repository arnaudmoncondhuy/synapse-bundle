<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value object immutable décrivant l'**objectif** d'un agent autonome.
 *
 * Introduit au Chantier D. Distinct de l'`Input` (qui décrit *ce qu'on demande
 * à l'agent dans cette invocation*), le `Goal` décrit *ce que l'agent doit
 * atteindre*. Un `Goal` peut rester constant à travers plusieurs appels /
 * replans / sous-agents, alors que l'Input change à chaque tour.
 *
 * Un agent **classique** (conversationnel, réactif, tool-calling) n'a pas
 * besoin de Goal — il consomme un Input et produit un Output, point. Un
 * agent **planificateur** ({@see \ArnaudMoncondhuy\SynapseCore\Agent\Autonomy\AbstractPlannerAgent})
 * part d'un Goal et itère jusqu'à ce qu'il le considère comme atteint.
 *
 * ## Sémantique des critères de succès
 *
 * Les `successCriteria` sont des descriptions en langage naturel que le
 * planificateur utilise pour s'auto-évaluer après chaque tour. Exemple :
 *
 * ```
 * new Goal(
 *   description: "Trouver le meilleur restaurant italien dans un rayon de 5 km",
 *   successCriteria: [
 *     "Au moins 3 restaurants identifiés avec adresse + note",
 *     "Un restaurant est explicitement recommandé avec justification",
 *     "Les avis utilisateurs mentionnés datent de moins de 6 mois",
 *   ],
 * )
 * ```
 *
 * Le planificateur les formate dans son prompt système pour que le LLM les
 * garde en tête pendant la boucle observe-plan-act-replan.
 *
 * ## Budget et deadline
 *
 * Un `Goal` **peut** porter un `BudgetLimit` propre qui s'ajoute à celui
 * éventuellement présent dans `AgentContext`. Le plus strict gagne (si les
 * deux disent « max 1€ » et l'autre « max 0.50€ », c'est 0.50€). Idem
 * pour `deadline`.
 */
final class Goal
{
    /**
     * @param string $description Objectif en langage naturel, phrase libre (pas de format imposé)
     * @param array<int, string> $successCriteria Liste ordonnée de critères en langage naturel. Le planificateur considère le goal atteint quand tous les critères sont remplis (ET logique). Liste vide = "l'agent décide seul via sa propre évaluation", réservé aux cas simples.
     * @param BudgetLimit|null $budget Budget propre au goal. Si null, hérite de `AgentContext::$budget`. Si renseigné, **s'applique en plus** de celui du contexte (chaque limite individuelle prend la valeur min entre les deux).
     * @param \DateTimeImmutable|null $deadline Échéance. Si non null, l'agent doit s'interrompre proprement avant cette date.
     * @param array<string, mixed> $metadata Métadonnées libres propagées dans les events et les logs (ex: source de la demande, priorité, étiquettes). Pas utilisé par le moteur, juste transporté pour observabilité.
     */
    public function __construct(
        public readonly string $description,
        public readonly array $successCriteria = [],
        public readonly ?BudgetLimit $budget = null,
        public readonly ?\DateTimeImmutable $deadline = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Sucre syntaxique pour un goal simple sans critères ni budget.
     */
    public static function of(string $description): self
    {
        return new self($description);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'success_criteria' => $this->successCriteria,
            'budget' => $this->budget?->toArray(),
            'deadline' => $this->deadline?->format(\DateTimeInterface::ATOM),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Format humain court utilisé dans les logs et les prompts système
     * du planificateur.
     */
    public function toPromptBlock(): string
    {
        $lines = ['**Objectif** : '.$this->description];
        if ([] !== $this->successCriteria) {
            $lines[] = '';
            $lines[] = '**Critères de succès** :';
            foreach ($this->successCriteria as $i => $criterion) {
                $lines[] = sprintf('%d. %s', $i + 1, $criterion);
            }
        }
        if (null !== $this->deadline) {
            $lines[] = '';
            $lines[] = '**Échéance** : '.$this->deadline->format('d/m/Y H:i');
        }
        if (null !== $this->budget) {
            $lines[] = '';
            $lines[] = '**Budget** : '.$this->formatBudget();
        }

        return implode("\n", $lines);
    }

    private function formatBudget(): string
    {
        $parts = [];
        if (null !== $this->budget?->maxCostEur) {
            $parts[] = sprintf('%.2f EUR max', $this->budget->maxCostEur);
        }
        if (null !== $this->budget?->maxDurationSeconds) {
            $parts[] = sprintf('%ds max', $this->budget->maxDurationSeconds);
        }
        if (null !== $this->budget?->maxTokens) {
            $parts[] = sprintf('%d tokens max', $this->budget->maxTokens);
        }

        return [] !== $parts ? implode(', ', $parts) : 'aucune contrainte';
    }
}
