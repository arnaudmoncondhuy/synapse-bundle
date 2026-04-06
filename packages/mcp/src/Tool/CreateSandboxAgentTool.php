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
    name: 'create_sandbox_agent',
    description: 'Create a temporary (sandbox) agent for testing. The agent is immediately resolvable by AgentResolver. Use list_presets to find available preset keys, or create one with create_sandbox_preset. Sandbox agents are cleaned up by cleanup_sandbox.'
)]
class CreateSandboxAgentTool
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $key,
        string $name,
        string $systemPrompt,
        ?string $description = null,
        ?string $presetKey = null,
        ?string $allowedToolNames = null,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        if (1 !== preg_match('/^[a-z0-9_-]+$/', $key) || strlen($key) > 50) {
            return [
                'status' => 'error',
                'error' => 'Invalid key format. Use lowercase alphanumeric, hyphens, underscores. Max 50 chars.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        if (null !== $this->agentRepository->findByKey($key)) {
            return [
                'status' => 'error',
                'error' => sprintf('Agent key "%s" already exists.', $key),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $preset = null !== $presetKey
            ? $this->presetRepository->findByKey($presetKey)
            : $this->presetRepository->findActive();

        if (null === $preset) {
            return [
                'status' => 'error',
                'error' => sprintf('Preset "%s" not found. Use list_presets to see available keys.', $presetKey ?? '(none)'),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $agent = new SynapseAgent();
        $agent->setKey($key);
        $agent->setName($name);
        $agent->setSystemPrompt($systemPrompt);
        $agent->setDescription($description ?? '');
        $agent->setModelPreset($preset);
        $agent->setAllowedToolNames(null !== $allowedToolNames && '' !== $allowedToolNames
            ? array_map('trim', explode(',', $allowedToolNames))
            : []);
        $agent->setIsSandbox(true);
        $agent->setIsBuiltin(false);
        $agent->setIsActive(true);

        $this->entityManager->persist($agent);
        $this->entityManager->flush();

        return [
            'status' => 'success',
            'agentKey' => $key,
            'agentName' => $name,
            'presetUsed' => $preset->getName(),
            'model' => $preset->getModel(),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
