<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\JsonPathLite;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;

/**
 * Exécuteur `conditional` (Chantier F — premier type non-agent livré).
 *
 * Un step `conditional` **ne parle pas à un LLM**. Il sert uniquement à
 * évaluer une expression JSONPath (ou une valeur littérale) et à exposer le
 * résultat sous `$.steps.<name>.output.data.matched` pour que les steps
 * suivants puissent router leur `input_mapping` ou leurs `outputs` en
 * conséquence.
 *
 * C'est la brique élémentaire qui débloque des workflows du type :
 *
 * ```yaml
 * steps:
 *   - name: classify
 *     agent_name: classifier
 *     input_mapping: { message: '$.inputs.message' }
 *
 *   - name: is_urgent
 *     type: conditional
 *     condition: '$.steps.classify.output.data.priority'
 *     equals: 'urgent'
 *
 *   - name: handle_urgent
 *     agent_name: urgent_handler
 *     input_mapping: { message: '$.inputs.message', go: '$.steps.is_urgent.output.data.matched' }
 * ```
 *
 * ## Format accepté
 *
 * - `condition` (obligatoire) : soit une expression JSONPath sur `$state`
 *   (ex: `$.steps.classify.output.data.priority`), soit une valeur littérale
 *   non-expression.
 * - `equals` (optionnel) : valeur de comparaison stricte (`===`). Si omis, le
 *   matched est un simple cast `(bool) $value` (truthy).
 *
 * ## Output retourné
 *
 * Toujours un Output **sans `answer`** (null) avec `data = {matched: bool,
 * value: <valeur évaluée>}`, et `usage` à zéro (pas de coût tokens). Ça permet
 * à `MultiAgent` d'agréger les tokens correctement sans polluer la facture.
 *
 * ## Scope volontairement minimal
 *
 * On **ne gère pas** encore :
 * - `not_equals`, `greater_than`, `less_than`, `in`, `contains` — attendre un
 *   besoin réel avant d'étendre la surface d'API ;
 * - le saut conditionnel (`skip_next: N` ou `goto: step_name`) — la sémantique
 *   reste strictement séquentielle pour l'instant, la conditionnalité se
 *   matérialise via `input_mapping` des steps suivants qui peuvent choisir
 *   de consommer ou non le matched.
 *
 * Ce minimalisme est intentionnel : le but de Chantier F minimal est de
 * **prouver que l'architecture NodeExecutor tient**. Les types plus riches
 * (`parallel`, `loop`, `sub_workflow`) attendront un besoin.
 */
final class ConditionalNodeExecutor implements NodeExecutorInterface
{
    public function supports(string $type): bool
    {
        return 'conditional' === $type;
    }

    public function execute(array $step, array $resolvedInput, array $state, AgentContext $childContext): Output
    {
        $stepName = (string) ($step['name'] ?? '?');
        $condition = $step['condition'] ?? null;
        if (!is_string($condition) || '' === $condition) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('conditional step "%s" has no "condition" expression', $stepName)
            );
        }

        // Si c'est une expression JSONPath, on l'évalue sur l'état accumulé.
        // Sinon, on prend la valeur telle quelle (littéral string).
        $value = JsonPathLite::isExpression($condition)
            ? JsonPathLite::evaluate($state, $condition)
            : $condition;

        // Mode comparaison stricte si `equals` fourni, sinon truthy check.
        if (array_key_exists('equals', $step)) {
            $matched = $value === $step['equals'];
        } else {
            $matched = (bool) $value;
        }

        return new Output(
            answer: null,
            data: [
                'matched' => $matched,
                'value' => $value,
            ],
            usage: [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        );
    }
}
