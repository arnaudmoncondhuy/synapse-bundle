<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'update_agent',
    description: 'Update the editable metadata of an existing Synapse agent (looked up by agentKey). Editable fields: name, emoji, description, modelPresetKey, allowedToolNames (comma-separated list, empty string for no restriction), isActive. To modify the systemPrompt use update_agent_system_prompt which has a dedicated HITL workflow. Parameters left null are left unchanged.'
)]
class UpdateAgentTool
{
    public function __construct(
        private readonly SynapseAgentRepository $agentRepository,
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $agentKey,
        ?string $name = null,
        ?string $emoji = null,
        ?string $description = null,
        ?string $modelPresetKey = null,
        ?string $allowedToolNames = null,
        ?bool $isActive = null,
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
                    'error' => sprintf('Agent "%s" not found. Use list_agents to see available keys.', $agentKey),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $changed = [];

            if (null !== $name && '' !== $name) {
                $agent->setName($name);
                $changed[] = 'name';
            }
            if (null !== $emoji && '' !== $emoji) {
                $agent->setEmoji($emoji);
                $changed[] = 'emoji';
            }
            if (null !== $description) {
                $agent->setDescription($description);
                $changed[] = 'description';
            }
            if (null !== $modelPresetKey && '' !== $modelPresetKey) {
                $preset = $this->presetRepository->findByKey($modelPresetKey);
                if (null === $preset) {
                    return [
                        'status' => 'error',
                        'agentKey' => $agentKey,
                        'error' => sprintf('Model preset "%s" not found. Use list_presets to see available keys.', $modelPresetKey),
                        'timestamp' => (new \DateTime())->format('c'),
                    ];
                }
                $agent->setModelPreset($preset);
                $changed[] = 'modelPreset';
            }
            if (null !== $allowedToolNames) {
                $tools = '' === $allowedToolNames
                    ? []
                    : array_values(array_filter(array_map('trim', explode(',', $allowedToolNames)), static fn (string $t): bool => '' !== $t));
                $agent->setAllowedToolNames($tools);
                $changed[] = 'allowedToolNames';
            }
            if (null !== $isActive) {
                $agent->setIsActive($isActive);
                $changed[] = 'isActive';
            }

            if ([] === $changed) {
                return [
                    'status' => 'success',
                    'agentKey' => $agentKey,
                    'message' => 'No fields provided — nothing changed.',
                    'changed' => [],
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $this->entityManager->flush();

            return [
                'status' => 'success',
                'agentKey' => $agentKey,
                'changed' => $changed,
                'agent' => [
                    'key' => $agent->getKey(),
                    'name' => $agent->getName(),
                    'emoji' => $agent->getEmoji(),
                    'description' => $agent->getDescription(),
                    'modelPresetKey' => $agent->getModelPreset()?->getKey(),
                    'allowedToolNames' => $agent->getAllowedToolNames(),
                    'isActive' => $agent->isActive(),
                    'isBuiltin' => $agent->isBuiltin(),
                ],
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'agentKey' => $agentKey,
                'error' => "Failed to update agent: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
