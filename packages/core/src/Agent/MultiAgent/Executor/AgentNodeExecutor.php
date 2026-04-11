<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Executor;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\StepInputResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;

/**
 * Exécuteur par défaut (Chantier F) : le type historique `agent` qui résout
 * un agent par son nom via {@see AgentResolver} et l'appelle avec l'input
 * déjà résolu par `MultiAgent`.
 *
 * Ce que ce service fait est **exactement** ce que `MultiAgent::call()` faisait
 * inline avant Chantier F : résoudre l'agent par `agent_name`, l'appeler avec
 * `Input::ofStructured($resolvedInput)` + `$childContext`, retourner son output.
 * La seule différence est que le code est maintenant isolé derrière l'interface
 * {@see NodeExecutorInterface}, ce qui permet au workflow de mixer d'autres
 * types de nœuds (`conditional` d'abord, plus tard `parallel`, `loop`, etc.)
 * sans alourdir `MultiAgent`.
 *
 * ## Type(s) supporté(s)
 *
 * - `'agent'` (valeur explicite dans la def)
 * - `''` (absence de clé `type`, fallback BC pour tous les workflows pré-F)
 */
final class AgentNodeExecutor implements NodeExecutorInterface
{
    public function __construct(
        private readonly AgentResolver $resolver,
    ) {
    }

    public function supports(string $type): bool
    {
        return 'agent' === $type || '' === $type;
    }

    public function execute(array $step, array $resolvedInput, array $state, AgentContext $childContext): Output
    {
        // Chantier K2 : lit agent_name dans config.agent_name (format Architect 2026-04-11+)
        // avec fallback sur step.agent_name (format flat historique).
        $agentName = StepInputResolver::readConfigField($step, 'agent_name');
        if (!is_string($agentName) || '' === $agentName) {
            throw WorkflowExecutionException::invalidDefinition(
                sprintf('agent step "%s" has no agent_name', (string) ($step['name'] ?? '?'))
            );
        }

        $agent = $this->resolver->resolve($agentName, $childContext);

        return $agent->call(
            Input::ofStructured($resolvedInput),
            ['context' => $childContext],
        );
    }
}
