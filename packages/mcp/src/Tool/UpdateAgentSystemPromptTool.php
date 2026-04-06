<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'update_agent_system_prompt',
    description: 'Propose a new system prompt for a Synapse agent. By default the proposal enters the HITL pending queue (Guardrail #3) and requires human approval. Pass mode="live" to apply immediately (not recommended for automated callers).'
)]
class UpdateAgentSystemPromptTool
{
    public function __construct(
        private readonly AgentRegistry $agentRegistry,
        private readonly EntityManagerInterface $em,
        private readonly PromptVersionRecorder $promptVersionRecorder,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $agentId,
        string $systemPrompt,
        ?string $reason = null,
        string $mode = 'pending',
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

        if (!in_array($mode, ['pending', 'live'], true)) {
            return [
                'status' => 'error',
                'agentId' => $agentId,
                'error' => sprintf('Invalid mode "%s". Expected "pending" or "live".', $mode),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            // Garde-fou #3 (HITL) : par défaut, une modification via MCP entre en
            // file d'attente `pending` et n'atterrit PAS sur l'agent. Un humain
            // doit explicitement approuver depuis l'admin. Le mode `live` reste
            // disponible pour les scripts de maintenance autorisés.
            $isPending = 'pending' === $mode;
            $version = $this->promptVersionRecorder->snapshot(
                $agent,
                $systemPrompt,
                'mcp:claude',
                $reason,
                flush: false,
                pending: $isPending,
            );

            if (!$isPending) {
                // Mode live : appliquer immédiatement sur l'agent.
                $agent->setSystemPrompt($systemPrompt);
                $this->em->persist($agent);
            }
            $this->em->flush();

            return [
                'status' => 'success',
                'agentId' => $agentId,
                'agentName' => $agent->getName(),
                'mode' => $mode,
                'message' => $isPending
                    ? 'Proposal queued for human review (pending). It will NOT affect the live agent until an admin approves it.'
                    : 'System prompt updated and applied live.',
                'versionId' => null !== $version ? $version->getId() : null,
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
