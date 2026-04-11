<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'update_preset',
    description: 'Update an existing Synapse model preset (looked up by key). Only generation parameters and metadata are editable here — provider and model are NOT mutable (create a new preset instead). Any parameter left null is left unchanged. Returns the updated preset snapshot.'
)]
class UpdatePresetTool
{
    public function __construct(
        private readonly SynapseModelPresetRepository $presetRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        string $key,
        ?string $name = null,
        ?float $generationTemperature = null,
        ?float $generationTopP = null,
        ?int $generationMaxOutputTokens = null,
        ?bool $streamingEnabled = null,
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
            $preset = $this->presetRepository->findByKey($key);
            if (null === $preset) {
                return [
                    'status' => 'error',
                    'key' => $key,
                    'error' => sprintf('Preset "%s" not found. Use list_presets to see available keys.', $key),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $changed = [];

            if (null !== $name && '' !== $name) {
                $preset->setName($name);
                $changed[] = 'name';
            }
            if (null !== $generationTemperature) {
                $preset->setGenerationTemperature($generationTemperature);
                $changed[] = 'generationTemperature';
            }
            if (null !== $generationTopP) {
                $preset->setGenerationTopP($generationTopP);
                $changed[] = 'generationTopP';
            }
            if (null !== $generationMaxOutputTokens) {
                $preset->setGenerationMaxOutputTokens($generationMaxOutputTokens);
                $changed[] = 'generationMaxOutputTokens';
            }
            if (null !== $streamingEnabled) {
                $preset->setStreamingEnabled($streamingEnabled);
                $changed[] = 'streamingEnabled';
            }
            if (null !== $isActive) {
                $preset->setIsActive($isActive);
                $changed[] = 'isActive';
            }

            if ([] === $changed) {
                return [
                    'status' => 'success',
                    'key' => $key,
                    'message' => 'No fields provided — nothing changed.',
                    'changed' => [],
                    'preset' => $this->snapshot($key),
                    'timestamp' => (new \DateTime())->format('c'),
                ];
            }

            $this->entityManager->flush();

            return [
                'status' => 'success',
                'key' => $key,
                'changed' => $changed,
                'preset' => $this->snapshot($key),
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'key' => $key,
                'error' => "Failed to update preset: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(string $key): ?array
    {
        $preset = $this->presetRepository->findByKey($key);
        if (null === $preset) {
            return null;
        }

        return [
            'key' => $preset->getKey(),
            'name' => $preset->getName(),
            'providerName' => $preset->getProviderName(),
            'model' => $preset->getModel(),
            'isActive' => $preset->isActive(),
            'isSandbox' => $preset->isSandbox(),
            'temperature' => $preset->getGenerationTemperature(),
            'topP' => $preset->getGenerationTopP(),
            'topK' => $preset->getGenerationTopK(),
            'maxOutputTokens' => $preset->getGenerationMaxOutputTokens(),
            'streamingEnabled' => $preset->isStreamingEnabled(),
        ];
    }
}
