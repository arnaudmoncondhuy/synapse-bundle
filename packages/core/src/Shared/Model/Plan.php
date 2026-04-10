<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value object immutable décrivant un **plan d'exécution** produit par un
 * agent planificateur à destination d'un moteur d'exécution.
 *
 * Introduit au Chantier D. C'est la structure pivot entre la « pensée »
 * du planificateur et « l'action » du moteur d'exécution. Un planner
 * produit un Plan JSON structuré (via LLM avec response_schema), le moteur
 * exécute les étapes, observe les résultats, puis le planner décide si
 * c'est fini ou s'il faut re-planifier (produire un nouveau Plan à partir
 * des observations).
 *
 * ## Relation avec SynapseWorkflow
 *
 * Un `Plan` est **compatible** avec le format pivot d'un SynapseWorkflow
 * (mêmes clés : `steps`, `input_mapping`, `outputs`). Pour persister un
 * plan et le rendre inspectable par l'utilisateur (vision « externalize
 * your thought »), le `PlannerAgent` peut appeler
 * `$planRepository->persistAsEphemeralWorkflow($plan)` — le plan devient
 * un workflow éphémère visible dans l'admin, promouvable si l'utilisateur
 * veut le réutiliser.
 *
 * ## Planif dynamique vs workflow statique
 *
 * Un `Plan` est **immutable** mais **jetable** : chaque replan produit un
 * nouveau Plan. Un `SynapseWorkflow` (statique) est éditable mais sa
 * définition évolue par versions explicites. Les deux coexistent.
 */
final class Plan
{
    /**
     * @param string $reasoning Phrase courte expliquant la stratégie globale du plan (propagée dans les events pour la transparency sidebar)
     * @param array<int, array{name: string, agent_name: string, input_mapping?: array<string, mixed>, output_key?: string, rationale?: string}> $steps Étapes au format pivot `SynapseWorkflow`. Chaque step : `name` (unique dans le plan), `agent_name` (doit être résolvable par `AgentResolver`), `input_mapping` et `output_key` optionnels, et `rationale` spécifique au step (description en langage naturel de ce que cette étape accomplit, affiché dans la transparency sidebar)
     * @param array<string, string> $outputs Mapping final au format pivot `{clé_finale: "$.steps.NAME.output.text"}`. Optionnel.
     * @param int $iteration Numéro de ce plan dans la séquence (0 = plan initial, 1 = premier replan, etc.). Incrémenté par `PlannerAgent` à chaque replan pour que l'observabilité puisse tracer l'évolution du raisonnement.
     */
    public function __construct(
        public readonly string $reasoning,
        public readonly array $steps,
        public readonly array $outputs = [],
        public readonly int $iteration = 0,
    ) {
    }

    /**
     * Construit un Plan à partir d'un payload structuré retourné par le LLM
     * (response_schema). Valide la forme minimale et lève si invalide.
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException si la structure ne respecte pas le format pivot minimal
     */
    public static function fromArray(array $data, int $iteration = 0): self
    {
        $reasoning = $data['reasoning'] ?? '';
        if (!is_string($reasoning)) {
            throw new \InvalidArgumentException('Plan::fromArray: "reasoning" must be a string.');
        }

        $stepsRaw = $data['steps'] ?? [];
        if (!is_array($stepsRaw) || [] === $stepsRaw) {
            throw new \InvalidArgumentException('Plan::fromArray: "steps" must be a non-empty array.');
        }

        $steps = [];
        $seenNames = [];
        foreach ($stepsRaw as $idx => $stepRaw) {
            if (!is_array($stepRaw)) {
                throw new \InvalidArgumentException(sprintf('Plan::fromArray: step %d must be an object.', $idx));
            }
            $name = $stepRaw['name'] ?? null;
            $agentName = $stepRaw['agent_name'] ?? null;
            if (!is_string($name) || '' === $name) {
                throw new \InvalidArgumentException(sprintf('Plan::fromArray: step %d has invalid "name".', $idx));
            }
            if (!is_string($agentName) || '' === $agentName) {
                throw new \InvalidArgumentException(sprintf('Plan::fromArray: step "%s" has invalid "agent_name".', $name));
            }
            if (in_array($name, $seenNames, true)) {
                throw new \InvalidArgumentException(sprintf('Plan::fromArray: duplicate step name "%s".', $name));
            }
            $seenNames[] = $name;

            $steps[] = [
                'name' => $name,
                'agent_name' => $agentName,
                'input_mapping' => is_array($stepRaw['input_mapping'] ?? null) ? $stepRaw['input_mapping'] : [],
                'output_key' => is_string($stepRaw['output_key'] ?? null) ? $stepRaw['output_key'] : $name,
                'rationale' => is_string($stepRaw['rationale'] ?? null) ? $stepRaw['rationale'] : '',
            ];
        }

        $outputsRaw = $data['outputs'] ?? [];
        $outputs = [];
        if (is_array($outputsRaw)) {
            foreach ($outputsRaw as $key => $expr) {
                if (is_string($key) && is_string($expr)) {
                    $outputs[$key] = $expr;
                }
            }
        }

        return new self(
            reasoning: $reasoning,
            steps: $steps,
            outputs: $outputs,
            iteration: $iteration,
        );
    }

    /**
     * Convertit le Plan en definition pivot de SynapseWorkflow, prête à être
     * persistée dans une entité SynapseWorkflow ou passée directement à
     * `MultiAgent::call()`.
     *
     * @return array{version: int, steps: array<int, array<string, mixed>>, outputs: array<string, string>}
     */
    public function toWorkflowDefinition(): array
    {
        // Strip rationale for compatibility with SynapseWorkflow::validatePivotStructure
        // which doesn't know about this Chantier-D-specific field.
        $cleanSteps = array_map(
            static function (array $step): array {
                unset($step['rationale']);

                return $step;
            },
            $this->steps,
        );

        return [
            'version' => 1,
            'steps' => $cleanSteps,
            'outputs' => $this->outputs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reasoning' => $this->reasoning,
            'iteration' => $this->iteration,
            'steps' => $this->steps,
            'outputs' => $this->outputs,
        ];
    }

    public function stepsCount(): int
    {
        return count($this->steps);
    }
}
