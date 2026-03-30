<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'run_agent_test',
    description: 'Execute a Synapse agent and return the response with metrics'
)]
class RunAgentTestTool
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $agentId,
        string $input,
        ?string $provider = null,
    ): array {
        try {
            $options = [
                'agent' => $agentId,
                'debug' => true,
                'streaming' => false,
            ];

            $result = $this->chatService->ask($input, $options);

            return [
                'status' => 'success',
                'agentId' => $agentId,
                'input' => $input,
                'answer' => $result['answer'] ?? '',
                'model' => $result['model'] ?? 'unknown',
                'usage' => $result['usage'] ?? [],
                'safety' => $result['safety'] ?? [],
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'agentId' => $agentId,
                'input' => $input,
                'error' => $e->getMessage(),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
