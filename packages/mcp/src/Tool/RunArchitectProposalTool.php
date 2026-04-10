<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectAgent;
use ArnaudMoncondhuy\SynapseCore\Governance\Architect\ArchitectProposalProcessor;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use Mcp\Capability\Attribute\McpTool;

/**
 * Tool MCP : expose {@see ArchitectAgent} pour que le LLM externe puisse
 * demander la génération d'un agent, d'un workflow ou l'amélioration d'un
 * prompt en langage naturel, puis persister la proposition comme éphémère.
 *
 * Le flow HITL est :
 *   1. LLM appelle `run_architect_proposal(action, description)`
 *   2. ArchitectAgent consulte son preset + schema structuré → proposition JSON
 *   3. Si `persist: true` (défaut), ArchitectProposalProcessor crée l'entité
 *      comme **éphémère + inactive** (visible dans l'admin, promouvable).
 *   4. L'utilisateur humain inspecte dans l'admin, teste via `run_agent_test`
 *      ou `run_workflow`, et promeut (bouton Promouvoir) ou laisse le GC
 *      la nettoyer après la retention window.
 *
 * Si `persist: false`, la proposition est retournée brute sans écriture —
 * mode "dry-run" pour qu'un LLM puisse inspecter plusieurs pistes avant de
 * choisir laquelle persister.
 */
#[McpTool(
    name: 'run_architect_proposal',
    description: 'Generate an agent, workflow, or improved prompt from a natural-language description using the ArchitectAgent (structured output). By default (persist=true), the proposal is immediately persisted as an ephemeral entity visible in the admin — inactive, awaiting human promotion. Use persist=false for dry-run mode to inspect proposals without writing to DB. Requires synapse.governance.architect_preset_key to be configured.'
)]
class RunArchitectProposalTool
{
    public function __construct(
        private readonly ArchitectAgent $architectAgent,
        private readonly ArchitectProposalProcessor $proposalProcessor,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $action,
        string $description,
        ?string $agentKey = null,
        ?string $instructions = null,
        ?bool $persist = true,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $validActions = ['create_agent', 'improve_prompt', 'create_workflow'];
        if (!in_array($action, $validActions, true)) {
            return [
                'status' => 'error',
                'error' => sprintf('Invalid action "%s". Valid: %s', $action, implode(', ', $validActions)),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        if ('improve_prompt' === $action && (null === $agentKey || '' === trim($agentKey))) {
            return [
                'status' => 'error',
                'error' => 'Action "improve_prompt" requires agentKey parameter.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $structured = [
            'action' => $action,
            'description' => $description,
        ];
        if (null !== $agentKey) {
            $structured['agent_key'] = $agentKey;
        }
        if (null !== $instructions) {
            $structured['instructions'] = $instructions;
        }

        // 1. Generate proposal via ArchitectAgent
        try {
            $output = $this->architectAgent->call(Input::ofStructured($structured));
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => 'ArchitectAgent call failed: '.$e->getMessage(),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $proposal = $output->getData();

        if (isset($proposal['error'])) {
            return [
                'status' => 'error',
                'error' => (string) $proposal['error'],
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        // For improve_prompt, the caller must inject the agent_key into the proposal
        // before passing it to the processor (ArchitectProposalProcessor expects it).
        if ('improve_prompt' === $action && null !== $agentKey) {
            $proposal['_agent_key'] = $agentKey;
        }

        $result = [
            'status' => 'success',
            'action' => $action,
            'proposal' => $this->sanitizeProposalForResponse($proposal),
            'persisted' => false,
            'entity' => null,
            'usage' => $output->getUsage(),
            'debugId' => $output->getDebugId(),
            'timestamp' => (new \DateTime())->format('c'),
        ];

        if (false === $persist) {
            return $result;
        }

        // 2. Persist as ephemeral entity via processor (HITL flow)
        try {
            $processed = $this->proposalProcessor->process($proposal, changedBy: 'mcp:architect');
        } catch (\Throwable $e) {
            $result['persistError'] = 'Processor failed: '.$e->getMessage();

            return $result;
        }

        $result['persisted'] = true;
        $result['entity'] = $this->describeEntity($processed);

        return $result;
    }

    /**
     * Retire les clés internes (`_action`, `_debug_id`, `_agent_key`) avant de
     * renvoyer la proposition au client MCP.
     *
     * @param array<string, mixed> $proposal
     *
     * @return array<string, mixed>
     */
    private function sanitizeProposalForResponse(array $proposal): array
    {
        unset($proposal['_action'], $proposal['_debug_id'], $proposal['_agent_key']);

        return $proposal;
    }

    /**
     * Décrit l'entité créée sous une forme exploitable par le LLM :
     * key, name, type, retention_until, URLs admin si calculables.
     *
     * @param array{type: string, entity: SynapseAgent|SynapseWorkflow, message: string} $processed
     *
     * @return array<string, mixed>
     */
    private function describeEntity(array $processed): array
    {
        $entity = $processed['entity'];

        if ($entity instanceof SynapseAgent) {
            return [
                'type' => 'agent',
                'key' => $entity->getKey(),
                'name' => $entity->getName(),
                'description' => $entity->getDescription(),
                'isEphemeral' => $entity->isEphemeral(),
                'isActive' => $entity->isActive(),
                'retentionUntil' => $entity->getRetentionUntil()?->format(\DateTimeInterface::ATOM),
                'processorMessage' => $processed['message'],
            ];
        }

        if ($entity instanceof SynapseWorkflow) {
            return [
                'type' => 'workflow',
                'workflowKey' => $entity->getWorkflowKey(),
                'name' => $entity->getName(),
                'description' => $entity->getDescription(),
                'stepsCount' => $entity->getStepsCount(),
                'isEphemeral' => $entity->isEphemeral(),
                'isActive' => $entity->isActive(),
                'retentionUntil' => $entity->getRetentionUntil()?->format(\DateTimeInterface::ATOM),
                'processorMessage' => $processed['message'],
            ];
        }

        return [
            'type' => $processed['type'],
            'processorMessage' => $processed['message'],
        ];
    }
}
