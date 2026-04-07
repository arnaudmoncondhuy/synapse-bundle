<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
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
                'source' => null !== $dbAgent ? 'db' : 'code',
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
