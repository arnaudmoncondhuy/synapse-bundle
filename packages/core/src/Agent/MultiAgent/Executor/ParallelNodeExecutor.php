<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\StepInputResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Exécuteur `parallel` (Chantier F phase 2).
 *
 * Orchestre un groupe de **branches indépendantes** qui partagent le même
 * état d'entrée et produisent chacune leur propre output. Les outputs sont
 * mergés sous `$output->data['branches'][<branchName>]` et les tokens
 * cumulés.
 *
 * ## Exécution séquentielle (décision 2026-04-11)
 *
 * L'implémentation initiale exécute les branches **l'une après l'autre**
 * dans l'ordre de déclaration. La sémantique « branches indépendantes »
 * est conservée (aucune branche ne peut lire l'output d'une autre — chaque
 * branche voit le même `$state` initial), seul le gain de latence concurrente
 * est absent. C'est un détail d'implémentation : on pourra plus tard passer
 * à une boucle Fiber PHP 8.1+ sans modifier le format de step ni la
 * sémantique. Les tests du format pivot ne doivent **pas** dépendre de
 * l'ordre d'exécution des branches (les outputs sont indexés par nom, pas
 * par position dans un array).
 *
 * ## Format step accepté
 *
 * ```json
 * {
 *   "name": "fanout",
 *   "type": "parallel",
 *   "branches": [
 *     { "name": "branch_a", "agent_name": "worker_a", "input_mapping": {...} },
 *     { "name": "branch_b", "type": "conditional", "condition": "...", "equals": "x" },
 *     { "name": "branch_c", "type": "sub_workflow", "workflow_key": "nested" }
 *   ]
 * }
 * ```
 *
 * Les branches peuvent être **n'importe quel type de NodeExecutor**, y
 * compris d'autres `parallel` (parallélisme imbriqué) ou des `sub_workflow`.
 * Le dispatch se fait via la même collection `$nodeExecutors` que
 * `MultiAgent` utilise — injectée en constructor via `AutowireIterator`.
 *
 * ## Output produit
 *
 * ```json
 * {
 *   "answer": null,
 *   "data": {
 *     "branches": {
 *       "branch_a": { "text": "...", "data": {...} },
 *       "branch_b": { "text": null, "data": { "matched": true, "value": "x" } },
 *       "branch_c": { "text": "...", "data": {...} }
 *     }
 *   },
 *   "usage": { "prompt_tokens": sum, "completion_tokens": sum, "total_tokens": sum }
 * }
 * ```
 *
 * Les steps suivants peuvent consommer une branche spécifique via
 * `$.steps.fanout.output.data.branches.branch_a.text`.
 *
 * ## Isolation de l'état par branche
 *
 * Chaque branche reçoit le `$state` du parent **en lecture seule**. Une
 * branche ne peut pas observer les outputs des autres branches (ils sont
 * mergés seulement après la fin de toutes les branches). Cette contrainte
 * est par design : ça force les définitions à être acycliques et rend le
 * futur passage à la concurrence réelle trivial.
 */
final class ParallelNodeExecutor implements NodeExecutorInterface
{
    /**
     * @var list<NodeExecutorInterface>
     */
    private readonly array $nodeExecutors;

    /**
     * @param iterable<NodeExecutorInterface> $nodeExecutors Collection de tous les exécuteurs
     *                                                      enregistrés, injectée via le tag
     *                                                      `synapse.node_executor` pour permettre
     *                                                      le dispatch récursif des branches.
     */
    public function __construct(
        #[AutowireIterator('synapse.node_executor')]
        iterable $nodeExecutors,
    ) {
        $this->nodeExecutors = is_array($nodeExecutors)
            ? array_values($nodeExecutors)
            : iterator_to_array($nodeExecutors, false);
    }

    public function supports(string $type): bool
    {
        return 'parallel' === $type;
    }

    public function execute(array $step, array $resolvedInput, array $state, AgentContext $childContext): Output
    {
        $stepName = (string) ($step['name'] ?? 'parallel');
        // Chantier K2 : branches dans config.branches avec fallback flat.
        $branches = StepInputResolver::readConfigField($step, 'branches');
        if (!is_array($branches) || [] === $branches) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('parallel step "%s" has empty or missing "branches"', $stepName)
            );
        }

        $branchOutputs = [];
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;

        foreach ($branches as $idx => $branch) {
            if (!is_array($branch) || !isset($branch['name']) || !is_string($branch['name'])) {
                throw WorkflowExecutionException::invalidDefinition(
                    sprintf('parallel step "%s" branch #%s is invalid', $stepName, (string) $idx)
                );
            }
            $branchName = $branch['name'];

            // Résoudre l'input de la branche à partir de l'état parent.
            // Chaque branche est résolue indépendamment, donc voit le même
            // snapshot d'état. Les branches ne peuvent pas se référencer
            // entre elles (par design — cf. isolation).
            $branchInput = StepInputResolver::resolve($branch, $state);

            $branchType = (string) ($branch['type'] ?? 'agent');
            $executor = $this->pickExecutor($branchType, $branchName, $stepName);

            // Contexte enfant par branche — permet le tracing arborescent
            // correct dans les debug logs (chaque branche a son parent_run_id
            // pointant vers ce step parallel).
            $branchContext = $childContext->createChild(
                parentRunId: $childContext->getRequestId(),
                childOrigin: 'workflow',
            );

            $branchOutput = $executor->execute($branch, $branchInput, $state, $branchContext);

            $branchOutputs[$branchName] = [
                'text' => $branchOutput->getAnswer(),
                'data' => $branchOutput->getData(),
            ];

            $usage = $branchOutput->getUsage();
            if (isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])) {
                $totalPromptTokens += $usage['prompt_tokens'];
            }
            if (isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])) {
                $totalCompletionTokens += $usage['completion_tokens'];
            }
        }

        return new Output(
            answer: null,
            data: ['branches' => $branchOutputs],
            usage: [
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
                'total_tokens' => $totalPromptTokens + $totalCompletionTokens,
            ],
        );
    }

    private function pickExecutor(string $type, string $branchName, string $parentStepName): NodeExecutorInterface
    {
        foreach ($this->nodeExecutors as $executor) {
            // Évite la récursion infinie : un parallel ne se dispatche pas
            // à lui-même. Si une branche est de type `parallel`, c'est une
            // AUTRE instance de ParallelNodeExecutor (même service, mais
            // scopé sur une sous-définition différente — pas de boucle).
            if ($executor->supports($type)) {
                return $executor;
            }
        }

        throw WorkflowExecutionException::invalidDefinition(
            sprintf('parallel step "%s": branch "%s" has unknown type "%s"', $parentStepName, $branchName, $type)
        );
    }
}
