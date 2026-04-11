<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'delete_workflow',
    description: 'Delete a Synapse workflow (looked up by workflowKey). Builtin workflows cannot be deleted. By default refuses to delete if the workflow is still referenced as a façade by at least one active agent (workflowKey column on SynapseAgent). Pass force=true to bypass the active-agent check (still forbids builtin deletion).'
)]
class DeleteWorkflowTool
{
    public function __construct(
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $workflowKey,
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
            $workflow = $this->workflowRepository->findByKey($workflowKey);
            if (null === $workflow) {
                return [
                    'status' => 'error',
                    'workflowKey' => $workflowKey,
                    'error' => sprintf('Workflow "%s" not found.', $workflowKey),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            if ($workflow->isBuiltin()) {
                return [
                    'status' => 'error',
                    'workflowKey' => $workflowKey,
                    'error' => sprintf('Workflow "%s" is a builtin workflow and cannot be deleted.', $workflowKey),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            /** @var array<int, SynapseAgent> $referencingAgents */
            $referencingAgents = $this->agentRepository->createQueryBuilder('a')
                ->andWhere('a.workflowKey = :key')
                ->andWhere('a.isActive = true')
                ->setParameter('key', $workflowKey)
                ->getQuery()
                ->getResult();

            if ([] !== $referencingAgents && true !== $force) {
                return [
                    'status' => 'error',
                    'workflowKey' => $workflowKey,
                    'error' => sprintf(
                        'Cannot delete workflow "%s": still referenced by %d active agent(s). Pass force=true to delete anyway.',
                        $workflowKey,
                        count($referencingAgents),
                    ),
                    'referencingAgents' => array_map(
                        static fn (SynapseAgent $a): string => $a->getKey(),
                        $referencingAgents,
                    ),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $this->entityManager->remove($workflow);
            $this->entityManager->flush();

            return [
                'status' => 'success',
                'workflowKey' => $workflowKey,
                'message' => sprintf('Workflow "%s" deleted.', $workflowKey),
                'forced' => true === $force && [] !== $referencingAgents,
                'orphanedAgents' => array_map(
                    static fn (SynapseAgent $a): string => $a->getKey(),
                    $referencingAgents,
                ),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'workflowKey' => $workflowKey,
                'error' => "Failed to delete workflow: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
