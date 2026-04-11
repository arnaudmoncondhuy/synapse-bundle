<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'delete_preset',
    description: 'Delete a Synapse model preset (looked up by key). By default refuses to delete if at least one agent explicitly references this preset, and returns the list of blocking agents. Pass force=true to bypass the reference check (use with caution — orphaned agents will fall back to the global active preset).'
)]
class DeletePresetTool
{
    public function __construct(
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $key,
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
            $preset = $this->presetRepository->findByKey($key);
            if (null === $preset) {
                return [
                    'status' => 'error',
                    'key' => $key,
                    'error' => sprintf('Preset "%s" not found.', $key),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $referencingAgents = $this->agentRepository->findByModelPreset($preset);

            if ([] !== $referencingAgents && true !== $force) {
                return [
                    'status' => 'error',
                    'key' => $key,
                    'error' => sprintf(
                        'Cannot delete preset "%s": still referenced by %d agent(s). Pass force=true to delete anyway.',
                        $key,
                        count($referencingAgents),
                    ),
                    'referencingAgents' => array_map(
                        static fn (SynapseAgent $a): string => $a->getKey(),
                        $referencingAgents,
                    ),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $this->entityManager->remove($preset);
            $this->entityManager->flush();

            return [
                'status' => 'success',
                'key' => $key,
                'message' => sprintf('Preset "%s" deleted.', $key),
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
                'key' => $key,
                'error' => "Failed to delete preset: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
