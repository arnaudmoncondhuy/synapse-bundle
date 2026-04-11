<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseMcp\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use Mcp\Capability\Attribute\McpTool;

#[McpTool(
    name: 'list_models',
    description: <<<'DESC'
        List all LLM models declared in Synapse provider YAML catalogs (Google Vertex AI, OVH, Anthropic, etc.), grouped by provider.

        Each model entry exposes :
        - modelId, label, provider, isEnabled, inDb (true if a row exists in synapse_model)
        - pricing : { input, output, outputImage, currency } (per 1M tokens)
        - capabilities : textGeneration, embedding, imageGeneration, thinking, functionCalling, parallelToolCalls, responseSchema, streaming, systemPrompt, safetySettings, topK, vision, acceptedMimeTypes (list of MIME types)
        - limits : maxInputTokens, maxOutputTokens, contextWindow
        - embeddingDimensions (only for embedding models)
        - providerRegions (Vertex AI regions where the model is available)
        - rgpdRisk (RGPD risk classification, if known)
        - deprecatedAt (ISO date if the model is scheduled for deprecation)

        The enabled state is computed by overlaying the synapse_model table (admin overrides) on top of the YAML defaults : a model absent from the table is enabled by default ; a model present with isEnabled=false is disabled.

        Use providerFilter to restrict the output to a single provider slug. Read-only — to toggle a model use the admin UI.
        DESC
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

                // Re-fetch via getCapabilities pour récupérer les champs absents
                // de getAllCapabilitiesMap (acceptedMimeTypes, supportsSystemPrompt,
                // supportsSafetySettings, supportsTopK, supportsParallelToolCalls).
                $fullCaps = $this->capabilityRegistry->getCapabilities($modelId);

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
                        'currency' => $dbEntry?->getCurrency() ?? $fullCaps->currency,
                    ],
                    'capabilities' => [
                        // Modes de génération
                        'textGeneration' => $fullCaps->supportsTextGeneration,
                        'embedding' => $fullCaps->supportsEmbedding,
                        'imageGeneration' => $fullCaps->supportsImageGeneration,
                        // Reasoning / structured output
                        'thinking' => $fullCaps->supportsThinking,
                        'functionCalling' => $fullCaps->supportsFunctionCalling,
                        'parallelToolCalls' => $fullCaps->supportsParallelToolCalls,
                        'responseSchema' => $fullCaps->supportsResponseSchema,
                        // Streaming et prompt système
                        'streaming' => $fullCaps->supportsStreaming,
                        'systemPrompt' => $fullCaps->supportsSystemPrompt,
                        // Safety / sampling
                        'safetySettings' => $fullCaps->supportsSafetySettings,
                        'topK' => $fullCaps->supportsTopK,
                        // Multimodal
                        'vision' => $fullCaps->supportsVision,
                        'acceptedMimeTypes' => $fullCaps->acceptedMimeTypes,
                    ],
                    'limits' => [
                        'maxInputTokens' => $fullCaps->maxInputTokens,
                        'maxOutputTokens' => $fullCaps->maxOutputTokens,
                        'contextWindow' => $fullCaps->contextWindow,
                    ],
                    'embeddingDimensions' => $fullCaps->dimensions,
                    'providerRegions' => $fullCaps->providerRegions,
                    'rgpdRisk' => $fullCaps->rgpdRisk,
                    'deprecatedAt' => $fullCaps->deprecatedAt,
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
