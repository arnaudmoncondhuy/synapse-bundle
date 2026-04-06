<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'cleanup_sandbox',
    description: 'Delete all sandbox (temporary) entities: workflow runs, workflows, agents, and presets. Safe to call multiple times (idempotent). Debug logs are preserved.'
)]
class CleanupSandboxTool
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly SynapseWorkflowRunRepository $runRepository,
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        // 1. Delete workflow runs for sandbox workflows
        $sandboxWorkflows = $this->workflowRepository->findSandbox();
        $keys = array_map(static fn ($w) => $w->getWorkflowKey(), $sandboxWorkflows);
        $runsDeleted = $this->runRepository->deleteByWorkflowKeys($keys);

        // 2. Delete sandbox workflows
        foreach ($sandboxWorkflows as $workflow) {
            $this->entityManager->remove($workflow);
        }

        // 3. Delete sandbox agents (before presets — agents reference presets via ManyToOne)
        $sandboxAgents = $this->agentRepository->findSandbox();
        foreach ($sandboxAgents as $agent) {
            $this->entityManager->remove($agent);
        }

        // 4. Delete sandbox presets (after agents that reference them)
        $sandboxPresets = $this->presetRepository->findSandbox();
        foreach ($sandboxPresets as $preset) {
            $this->entityManager->remove($preset);
        }

        $this->entityManager->flush();

        return [
            'status' => 'success',
            'workflowRunsDeleted' => $runsDeleted,
            'workflowsDeleted' => count($sandboxWorkflows),
            'agentsDeleted' => count($sandboxAgents),
            'presetsDeleted' => count($sandboxPresets),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
