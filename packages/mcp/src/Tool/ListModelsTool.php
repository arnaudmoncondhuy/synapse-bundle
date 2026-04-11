<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'list_models',
    description: 'List all LLM models declared in Synapse provider YAML catalogs (Google Vertex AI, OVH, Anthropic, etc.), grouped by provider. Each model includes its modelId, label, provider, capabilities (vision, thinking, function calling, streaming, response schema), pricing (input/output per 1M tokens), currency, and effective enabled state. The enabled state is computed by overlaying the synapse_model table (admin overrides) on top of the YAML defaults: a model absent from the table is enabled by default; a model present with isEnabled=false is disabled. Use providerFilter to restrict the output to a single provider slug. Read-only — to toggle a model use the admin UI.'
)]
class ListModelsTool
{
    public function __construct(
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly SynapseModelRepository $modelRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    /** @return array<string, mixed> */
    public function __invoke(
        ?string $providerFilter = null,
    ): array {
        if (!$this->permissionChecker->canAccessAdmin()) {
            return [
                'status' => 'error',
                'error' => 'Access denied. Admin role required.',
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }

        try {
            // Source de vérité : les YAML providers (catalogue déclaratif complet)
            $catalog = $this->capabilityRegistry->getAllCapabilitiesMap();

            // Overlay : les overrides admin stockés en base (pricing custom + isEnabled)
            $dbOverrides = [];
            foreach ($this->modelRepository->findAll() as $dbModel) {
                $dbOverrides[$dbModel->getModelId()] = $dbModel;
            }

            // Modèles désactivés explicitement en base
            $disabledIds = array_flip($this->modelRepository->findDisabledModelIds());

            $grouped = [];
            foreach ($catalog as $modelId => $caps) {
                $providerName = is_string($caps['provider'] ?? null) ? (string) $caps['provider'] : 'unknown';

                if (null !== $providerFilter && '' !== $providerFilter && $providerName !== $providerFilter) {
                    continue;
                }

                $dbEntry = $dbOverrides[$modelId] ?? null;

                $grouped[$providerName][] = [
                    'modelId' => $modelId,
                    'label' => $dbEntry?->getLabel() ?? $modelId,
                    'provider' => $providerName,
                    'isEnabled' => !isset($disabledIds[$modelId]),
                    'inDb' => null !== $dbEntry,
                    'pricing' => [
                        'input' => $dbEntry?->getPricingInput() ?? $caps['pricingInput'] ?? null,
                        'output' => $dbEntry?->getPricingOutput() ?? $caps['pricingOutput'] ?? null,
                        'outputImage' => $dbEntry?->getPricingOutputImage() ?? $caps['pricingOutputImage'] ?? null,
                        'currency' => $dbEntry?->getCurrency() ?? 'USD',
                    ],
                    'capabilities' => [
                        'textGeneration' => $caps['supportsTextGeneration'] ?? false,
                        'embedding' => $caps['supportsEmbedding'] ?? false,
                        'imageGeneration' => $caps['supportsImageGeneration'] ?? false,
                        'thinking' => $caps['supportsThinking'] ?? false,
                        'functionCalling' => $caps['supportsFunctionCalling'] ?? false,
                        'streaming' => $caps['supportsStreaming'] ?? false,
                        'vision' => $caps['supportsVision'] ?? false,
                        'responseSchema' => $caps['supportsResponseSchema'] ?? false,
                    ],
                    'maxInputTokens' => $caps['maxInputTokens'] ?? null,
                    'maxOutputTokens' => $caps['maxOutputTokens'] ?? null,
                    'deprecatedAt' => $caps['deprecatedAt'] ?? null,
                ];
            }

            $count = 0;
            foreach ($grouped as $models) {
                $count += count($models);
            }

            return [
                'status' => 'success',
                'count' => $count,
                'providerFilter' => $providerFilter,
                'models' => $grouped,
                'timestamp' => (new \DateTime())->format('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => "Failed to list models: {$e->getMessage()}",
                'timestamp' => (new \DateTime())->format('c'),
            ];
        }
    }
}
