<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'delete_agent',
    description: 'Delete a Synapse agent (looked up by agentKey). Builtin (code) agents cannot be deleted. By default refuses to delete if the agent is referenced by at least one active workflow, and returns the blocking workflows. Pass force=true to bypass the active-workflow check (still forbids builtin deletion).'
)]
class DeleteAgentTool
{
    public function __construct(
        private readonly SynapseAgentRepository $agentRepository,
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $agentKey,
        ?bool $force = false,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            $agent = $this->agentRepository->findByKey($agentKey);
            if (null === $agent) {
                return [
                    'status' => 'error',
                    'agentKey' => $agentKey,
                    'error' => sprintf('Agent "%s" not found.', $agentKey),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            if ($agent->isBuiltin()) {
                return [
                    'status' => 'error',
                    'agentKey' => $agentKey,
                    'error' => sprintf('Agent "%s" is a builtin (code) agent and cannot be deleted.', $agentKey),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $referencingWorkflows = array_values(array_filter(
                $this->workflowRepository->findByAgentKey($agentKey),
                static fn (SynapseWorkflow $w): bool => $w->isActive(),
            ));

            if ([] !== $referencingWorkflows && true !== $force) {
                return [
                    'status' => 'error',
                    'agentKey' => $agentKey,
                    'error' => sprintf(
                        'Cannot delete agent "%s": still referenced by %d active workflow(s). Pass force=true to delete anyway.',
                        $agentKey,
                        count($referencingWorkflows),
                    ),
                    'referencingWorkflows' => array_map(
                        static fn (SynapseWorkflow $w): string => $w->getWorkflowKey(),
                        $referencingWorkflows,
                    ),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $this->entityManager->remove($agent);
            $this->entityManager->flush();

            return [
                'status' => 'success',
                'agentKey' => $agentKey,
                'message' => sprintf('Agent "%s" deleted.', $agentKey),
                'forced' => true === $force && [] !== $referencingWorkflows,
                'orphanedWorkflows' => array_map(
                    static fn (SynapseWorkflow $w): string => $w->getWorkflowKey(),
                    $referencingWorkflows,
                ),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'agentKey' => $agentKey,
                'error' => "Failed to delete agent: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
