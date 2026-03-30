<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'update_agent_system_prompt',
    description: 'Update the system prompt of a Synapse agent. Used by AI Coach to apply improvements.'
)]
class UpdateAgentSystemPromptTool
{
    public function __construct(
        private readonly AgentRegistry $agentRegistry,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $agentId,
        string $systemPrompt,
        ?string $reason = null,
    ): array {
        // Verify agent exists and user has access
        $agent = $this->agentRegistry->get($agentId);
        if (null === $agent) {
            return [
                'status' => 'error',
                'agentId' => $agentId,
                'error' => "Agent not found: '$agentId'",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            // Update the system prompt
            $agent->setSystemPrompt($systemPrompt);
            $this->em->persist($agent);
            $this->em->flush();

            return [
                'status' => 'success',
                'agentId' => $agentId,
                'agentName' => $agent->getName(),
                'message' => 'System prompt updated successfully',
                'reason' => $reason ?? 'AI Coach optimization',
                'newSystemPrompt' => $systemPrompt,
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'agentId' => $agentId,
                'error' => "Failed to update system prompt: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
