<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent;

use Psr\Log\LoggerInterface;

/**
 * Résout l'input d'un step à partir de son `input_mapping` et de l'état
 * accumulé du run — utilitaire statique partagé par `MultiAgent` et les
 * `NodeExecutorInterface` composites (Chantier F phase 2 : `parallel`,
 * `loop`).
 *
 * ## Pourquoi un helper statique
 *
 * La logique vivait initialement en méthode privée de `MultiAgent::resolveStepInput()`.
 * Chantier F minimal (types `conditional`) n'en avait pas besoin — l'exécuteur
 * lit directement `$state`. Mais Chantier F phase 2 introduit des exécuteurs
 * composites (`parallel` qui orchestre N branches, `loop` qui itère N fois
 * un step template) qui doivent eux-mêmes résoudre l'input de leurs sous-steps
 * **sans dupliquer la logique**. L'extraction en helper statique est la
 * solution la plus simple : zéro DI, zéro couplage, utilisable depuis
 * n'importe où.
 *
 * ## Sémantique
 *
 * - Si `input_mapping` est absent ou vide → **passe-plat** : retourne les
 *   inputs racine du workflow (`$state['inputs']`), avec priorité au champ
 *   `message` s'il existe. C'est ce qu'un agent conversationnel attend par
 *   défaut, et ce qui évite qu'un planner produise des prompts vides.
 * - Sinon → pour chaque clé du mapping, évalue la valeur comme une
 *   expression JSONPath (si elle commence par `$.`) ou la copie telle quelle.
 *   Un JSONPath qui résout à `null` émet un warning via le logger optionnel
 *   — c'est presque toujours le signe d'un typo dans le chemin.
 */
final class StepInputResolver
{
    /**
     * @param array<string, mixed> $step  La définition du step (clé `input_mapping` optionnelle)
     * @param array<string, mixed> $state L'état accumulé (`inputs` + `steps`)
     *
     * @return array<string, mixed> L'input résolu à passer à l'exécuteur du step
     */
    public static function resolve(
        array $step,
        array $state,
        ?LoggerInterface $logger = null,
        ?string $stepName = null,
        ?string $workflowRunId = null,
        ?string $workflowKey = null,
    ): array {
        $mapping = $step['input_mapping'] ?? null;

        // Fallback passe-plat : pas de mapping → propage les inputs racine.
        if (!is_array($mapping) || [] === $mapping) {
            $rootInputs = $state['inputs'] ?? [];
            if (is_array($rootInputs) && [] !== $rootInputs) {
                if (isset($rootInputs['message']) && is_string($rootInputs['message']) && '' !== $rootInputs['message']) {
                    return ['message' => $rootInputs['message']];
                }

                return $rootInputs;
            }

            return [];
        }

        $resolved = [];
        foreach ($mapping as $key => $expression) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($expression) && JsonPathLite::isExpression($expression)) {
                $value = JsonPathLite::evaluate($state, $expression);
                $resolved[$key] = $value;
                if (null === $value && null !== $logger) {
                    $logger->warning(
                        'Workflow step "{step}" input_mapping "{key}" → "{path}" résout à null. Typo dans le chemin ?',
                        [
                            'step' => $stepName ?? ($step['name'] ?? '?'),
                            'key' => $key,
                            'path' => $expression,
                            'workflow_run_id' => $workflowRunId,
                            'workflow_key' => $workflowKey,
                        ],
                    );
                }
            } else {
                $resolved[$key] = $expression;
            }
        }

        return $resolved;
    }
}
