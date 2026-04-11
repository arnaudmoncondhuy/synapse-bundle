<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'run_agent_test',
    description: 'Execute any Synapse agent (DB or code) and return the response with metrics. Use list_agents to get available agent keys.'
)]
class RunAgentTestTool
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly AgentRegistry $agentRegistry,
        private readonly CodeAgentRegistry $codeAgentRegistry,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly AgentResolver $agentResolver,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $agentKey,
        string $input,
        ?int $userId = null,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        // Résolution unifiée : DB d'abord, puis code agent en fallback
        $dbAgent = $this->agentRegistry->get($agentKey);
        $codeAgent = $this->codeAgentRegistry->get($agentKey);

        if (null === $dbAgent && null === $codeAgent) {
            return [
                'status' => 'error',
                'agentKey' => $agentKey,
                'error' => "Agent not found: '$agentKey'. Use list_agents to get available keys.",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $agentName = $dbAgent?->getName() ?? $codeAgent?->getLabel() ?? $agentKey;

        try {
            // Pour un agent **code** avec une logique execute() custom (ex:
            // DemoPlannerAgent du Chantier D, ou PresetValidatorAgent), il faut
            // absolument passer par $agent->call() pour que la logique métier
            // spécifique soit invoquée. Sinon ChatService::ask() skipperait
            // execute() et ferait un simple tour conversationnel basé uniquement
            // sur le system prompt de l'agent.
            //
            // Pour un agent **DB** (ConfiguredAgent), il n'y a pas de logique
            // custom → ChatService::ask() est suffisant et identique à ce que
            // ConfiguredAgent ferait de toute façon.
            if (null !== $codeAgent) {
                $context = $this->agentResolver->createRootContext(
                    userId: null !== $userId ? (string) $userId : null,
                    origin: 'mcp_test',
                );
                $resolvedAgent = $this->agentResolver->resolve($agentKey, $context);
                $output = $resolvedAgent->call(
                    Input::ofMessage($input),
                    ['context' => $context],
                );

                return [
                    'status' => 'success',
                    'agentKey' => $agentKey,
                    'agentName' => $agentName,
                    'source' => 'code',
                    'input' => $input,
                    'answer' => $output->getAnswer() ?? '',
                    'data' => $output->getData(),
                    'debugId' => $output->getDebugId(),
                    'usage' => $output->getUsage(),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            // Agent DB : ChatService::ask() directement (legacy).
            $options = [
                'agent' => $agentKey,
                'streaming' => false,
            ];

            if (null !== $userId) {
                $options['user_id'] = (string) $userId;
            }

            $result = $this->chatService->ask($input, $options);

            return [
                'status' => 'success',
                'agentKey' => $agentKey,
                'agentName' => $agentName,
                'source' => 'db',
                'input' => $input,
                'answer' => $result['answer'] ?? '',
                'model' => $result['model'] ?? 'unknown',
                'debugId' => $result['debug_id'] ?? null,
                'usage' => $result['usage'] ?? [],
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'agentKey' => $agentKey,
                'error' => $e->getMessage(),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
