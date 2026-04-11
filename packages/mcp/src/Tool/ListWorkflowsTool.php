<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'list_workflows',
    description: 'List all Synapse workflows with their metadata (name, description, version, active/builtin/sandbox flags, steps count). By default excludes sandbox workflows — pass includeSandbox=true to also see temporary ones. Use inspect_workflow_run to see execution history for a specific workflow.'
)]
class ListWorkflowsTool
{
    public function __construct(
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        ?bool $includeSandbox = false,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            $workflows = true === $includeSandbox
                ? $this->workflowRepository->findAll()
                : $this->workflowRepository->findAllOrdered();

            return [
                'status' => 'success',
                'count' => count($workflows),
                'workflows' => array_map(static function (SynapseWorkflow $w): array {
                    $definition = $w->getDefinition();
                    $steps = $definition['steps'] ?? [];

                    return [
                        'workflowKey' => $w->getWorkflowKey(),
                        'name' => $w->getName(),
                        'description' => $w->getDescription(),
                        'version' => $w->getVersion(),
                        'isActive' => $w->isActive(),
                        'isBuiltin' => $w->isBuiltin(),
                        'isSandbox' => $w->isSandbox(),
                        'sortOrder' => $w->getSortOrder(),
                        'stepsCount' => is_array($steps) ? count($steps) : 0,
                    ];
                }, $workflows),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => "Failed to list workflows: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
