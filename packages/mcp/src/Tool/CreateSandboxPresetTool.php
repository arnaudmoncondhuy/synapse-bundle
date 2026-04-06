<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'create_sandbox_preset',
    description: 'Create a temporary (sandbox) model preset for testing. Choose a provider and model from the known models list. Use list_presets to see existing presets. Sandbox presets are cleaned up by cleanup_sandbox.'
)]
class CreateSandboxPresetTool
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $key,
        string $name,
        string $providerName,
        string $model,
        ?float $temperature = null,
        ?float $topP = null,
        ?int $maxOutputTokens = null,
        ?bool $streamingEnabled = null,
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

        if (null !== $this->presetRepository->findByKey($key)) {
            return [
                'status' => 'error',
                'error' => sprintf('Preset key "%s" already exists.', $key),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        if (!$this->capabilityRegistry->isKnownModel($model)) {
            $available = $this->capabilityRegistry->getModelsForProvider($providerName);

            return [
                'status' => 'error',
                'error' => sprintf(
                    'Model "%s" is not known. Available models for provider "%s": %s',
                    $model,
                    $providerName,
                    [] !== $available ? implode(', ', $available) : '(none — check provider name)',
                ),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        $preset = new SynapseModelPreset();
        $preset->setKey($key);
        $preset->setName($name);
        $preset->setProviderName($providerName);
        $preset->setModel($model);
        $preset->setGenerationTemperature($temperature ?? 1.0);
        $preset->setGenerationTopP($topP ?? 0.95);
        $preset->setGenerationMaxOutputTokens($maxOutputTokens);
        $preset->setStreamingEnabled($streamingEnabled ?? false);
        $preset->setIsSandbox(true);
        $preset->setIsActive(false);

        $this->entityManager->persist($preset);
        $this->entityManager->flush();

        return [
            'status' => 'success',
            'presetKey' => $key,
            'name' => $name,
            'providerName' => $providerName,
            'model' => $model,
            'temperature' => $preset->getGenerationTemperature(),
            'streamingEnabled' => $preset->isStreamingEnabled(),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
