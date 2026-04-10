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
    description: 'Delete ephemeral (temporary) entities whose retention window has expired: workflow runs, workflows, agents, and presets. Respects the retention_until date set at creation — ephemerals within their window survive. Use force=true to delete ALL ephemerals regardless of retention (legacy behavior, useful for explicit cleanup). Debug logs are always preserved.'
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
    public function __invoke(?bool $force = false): array
    {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        // Respecte la retention window par défaut — seuls les éphémères expirés
        // sont supprimés. `force: true` purge tous les éphémères (legacy behavior).
        $workflows = $force
            ? $this->workflowRepository->findEphemeral()
            : $this->workflowRepository->findExpiredEphemeral();

        $agents = $force
            ? $this->agentRepository->findEphemeral()
            : $this->agentRepository->findExpiredEphemeral();

        $presets = $force
            ? $this->presetRepository->findEphemeral()
            : $this->presetRepository->findExpiredEphemeral();

        // 1. Delete workflow runs for targeted workflows
        $keys = array_map(static fn ($w) => $w->getWorkflowKey(), $workflows);
        $runsDeleted = $this->runRepository->deleteByWorkflowKeys($keys);

        // 2. Delete workflows
        foreach ($workflows as $workflow) {
            $this->entityManager->remove($workflow);
        }

        // 3. Delete agents (before presets — agents reference presets via ManyToOne)
        foreach ($agents as $agent) {
            $this->entityManager->remove($agent);
        }

        // 4. Delete presets (after agents that reference them)
        foreach ($presets as $preset) {
            $this->entityManager->remove($preset);
        }

        $this->entityManager->flush();

        return [
            'status' => 'success',
            'mode' => $force ? 'force' : 'retention-aware',
            'workflowRunsDeleted' => $runsDeleted,
            'workflowsDeleted' => count($workflows),
            'agentsDeleted' => count($agents),
            'presetsDeleted' => count($presets),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
