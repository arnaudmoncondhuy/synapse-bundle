<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'list_presets',
    description: 'List all available Synapse model presets with their configuration (provider, model, generation params). Use includeSandbox=true to also see temporary sandbox presets.'
)]
class ListPresetsTool
{
    public function __construct(
        private readonly SynapseModelPresetRepository $presetRepository,
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

        $presets = $includeSandbox
            ? $this->presetRepository->findAll()
            : $this->presetRepository->findAllPresets();

        return [
            'status' => 'success',
            'count' => count($presets),
            'presets' => array_map(static fn ($p) => [
                'key' => $p->getKey(),
                'name' => $p->getName(),
                'providerName' => $p->getProviderName(),
                'model' => $p->getModel(),
                'isActive' => $p->isActive(),
                'isEphemeral' => $p->isEphemeral(),
                'temperature' => $p->getGenerationTemperature(),
                'topP' => $p->getGenerationTopP(),
                'topK' => $p->getGenerationTopK(),
                'maxOutputTokens' => $p->getGenerationMaxOutputTokens(),
                'streamingEnabled' => $p->isStreamingEnabled(),
            ], $presets),
            'timestamp' => (new \DateTime())->format('c'),
        ];
    }
}
