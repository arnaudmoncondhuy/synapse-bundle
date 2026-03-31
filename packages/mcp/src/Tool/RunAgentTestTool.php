<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'run_agent_test',
    description: 'Execute any Synapse agent and return the response with metrics. Use list_agents to get available agent keys.'
)]
class RunAgentTestTool
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly AgentRegistry $agentRegistry,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $agentKey,
        string $input,
        ?int $userId = null,
    ): array {
        $agent = $this->agentRegistry->get($agentKey);
        if (null === $agent) {
            return [
                'status' => 'error',
                'agentKey' => $agentKey,
                'error' => "Agent not found: '$agentKey'. Use list_agents to get available keys.",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            $options = [
                'agent' => $agentKey,
                'debug' => true,
                'streaming' => false,
            ];

            if (null !== $userId) {
                $options['user_id'] = (string) $userId;
            }

            $result = $this->chatService->ask($input, $options);

            return [
                'status' => 'success',
                'agentKey' => $agentKey,
                'agentName' => $agent->getName(),
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
