<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\JsonPathLite;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\StepInputResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Exécuteur `loop` (Chantier F phase 2).
 *
 * Itère un **step template** sur un array résolu par JSONPath. À chaque
 * itération, l'élément courant est injecté dans l'état sous un alias
 * (défaut `item`), puis le step template est dispatché via le
 * NodeExecutor adapté. Les outputs des itérations sont collectés dans
 * `$output->data['iterations']`.
 *
 * ## Format step accepté
 *
 * ```json
 * {
 *   "name": "per_doc",
 *   "type": "loop",
 *   "items_path": "$.inputs.documents",
 *   "item_alias": "doc",
 *   "max_iterations": 50,
 *   "step": {
 *     "name": "process_one",
 *     "agent_name": "processor",
 *     "input_mapping": { "text": "$.inputs.doc" }
 *   }
 * }
 * ```
 *
 * - `items_path` (requis) : expression JSONPath qui doit résoudre à un array.
 * - `step` (requis) : template du step à exécuter pour chaque élément.
 * - `item_alias` (optionnel, défaut `item`) : clé sous laquelle l'élément
 *   courant est exposé dans `$state['inputs']` pour que le template y
 *   accède via `$.inputs.<alias>`. L'index 0-based est aussi exposé sous
 *   `$state['inputs']['index']`.
 * - `max_iterations` (optionnel, défaut 50) : garde-fou contre les arrays
 *   gigantesques qui feraient exploser la facturation tokens.
 *
 * ## Output produit
 *
 * ```json
 * {
 *   "answer": null,
 *   "data": {
 *     "iterations": [
 *       { "item": <element_0>, "output": { "text": "...", "data": {...} } },
 *       { "item": <element_1>, "output": { "text": "...", "data": {...} } },
 *       ...
 *     ]
 *   },
 *   "usage": { sum de toutes les itérations }
 * }
 * ```
 *
 * ## Isolation
 *
 * Chaque itération reçoit une copie de `$state` où seul
 * `$state['inputs'][<alias>]` + `$state['inputs']['index']` sont ajoutés.
 * Le `$state['steps']` parent reste accessible mais une itération ne peut
 * pas lire les outputs des itérations précédentes (pas de `$state['steps']`
 * partagé entre les itérations). C'est par design : un loop doit être une
 * fonction pure `item → output`, sans dépendance inter-itération. Si tu as
 * besoin d'accumulation, utilise un agent qui reçoit l'array complet en
 * une seule fois au lieu d'un loop.
 */
final class LoopNodeExecutor implements NodeExecutorInterface
{
    /** Garde-fou par défaut : refuse d'itérer au-delà de 50 items sans override explicite. */
    public const DEFAULT_MAX_ITERATIONS = 50;

    /**
     * @var list<NodeExecutorInterface>
     */
    private readonly array $nodeExecutors;

    /**
     * @param iterable<NodeExecutorInterface> $nodeExecutors
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
        return 'loop' === $type;
    }

    public function execute(array $step, array $resolvedInput, array $state, AgentContext $childContext): Output
    {
        $stepName = (string) ($step['name'] ?? 'loop');

        $itemsPath = $step['items_path'] ?? null;
        if (!is_string($itemsPath) || '' === $itemsPath) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('loop step "%s" missing "items_path"', $stepName)
            );
        }

        $template = $step['step'] ?? null;
        if (!is_array($template) || !isset($template['name'])) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('loop step "%s" missing or invalid "step" template', $stepName)
            );
        }

        $items = JsonPathLite::isExpression($itemsPath)
            ? JsonPathLite::evaluate($state, $itemsPath)
            : null;

        if (!is_array($items)) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('loop step "%s": items_path "%s" did not resolve to an array (got %s)', $stepName, $itemsPath, get_debug_type($items))
            );
        }

        $maxIterations = isset($step['max_iterations']) && is_int($step['max_iterations']) && $step['max_iterations'] > 0
            ? $step['max_iterations']
            : self::DEFAULT_MAX_ITERATIONS;

        if (count($items) > $maxIterations) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('loop step "%s": %d items exceeds max_iterations=%d', $stepName, count($items), $maxIterations)
            );
        }

        $alias = isset($step['item_alias']) && is_string($step['item_alias']) && '' !== $step['item_alias']
            ? $step['item_alias']
            : 'item';

        $templateType = (string) ($template['type'] ?? 'agent');
        $executor = $this->pickExecutor($templateType, $stepName);

        $iterations = [];
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;

        foreach ($items as $index => $item) {
            // State enrichi avec l'item courant + l'index, sans polluer
            // le state parent (on copie).
            $iterState = $state;
            $iterState['inputs'] = array_merge(
                $iterState['inputs'] ?? [],
                [$alias => $item, 'index' => $index],
            );

            $iterInput = StepInputResolver::resolve($template, $iterState);

            $iterContext = $childContext->createChild(
                parentRunId: $childContext->getRequestId(),
                childOrigin: 'workflow',
            );

            $iterOutput = $executor->execute($template, $iterInput, $iterState, $iterContext);

            $iterations[] = [
                'item' => $item,
                'output' => [
                    'text' => $iterOutput->getAnswer(),
                    'data' => $iterOutput->getData(),
                ],
            ];

            $usage = $iterOutput->getUsage();
            if (isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])) {
                $totalPromptTokens += $usage['prompt_tokens'];
            }
            if (isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])) {
                $totalCompletionTokens += $usage['completion_tokens'];
            }
        }

        return new Output(
            answer: null,
            data: ['iterations' => $iterations],
            usage: [
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
                'total_tokens' => $totalPromptTokens + $totalCompletionTokens,
            ],
        );
    }

    private function pickExecutor(string $type, string $loopStepName): NodeExecutorInterface
    {
        foreach ($this->nodeExecutors as $executor) {
            if ($executor->supports($type)) {
                return $executor;
            }
        }

        throw WorkflowExecutionException::invalidDefinition(
            sprintf('loop step "%s": template has unknown type "%s"', $loopStepName, $type)
        );
    }
}
