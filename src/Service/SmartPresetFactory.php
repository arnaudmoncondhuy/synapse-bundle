<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

/**
 * Smart Preset Factory
 *
 * Generates pre-tested configuration profiles for different use cases.
 * These presets are applied automatically when a user selects them,
 * and can be further customized in advanced mode.
 */
class SmartPresetFactory
{
    /**
     * Preset definitions with their recommended parameters.
     */
    private const PRESETS = [
        'fast' => [
            'label' => '⚡ Rapide',
            'description' => 'Optimisé pour la latence basse. Idéal pour les chatbots conversationnels.',
            'generation_temperature' => 0.5,
            'generation_top_p' => 0.8,
            'generation_top_k' => 30,
            'generation_max_output_tokens' => 1000,
            'thinking_enabled' => false,
            'thinking_budget' => 0,
            'safety_enabled' => false,
            'context_caching_enabled' => false,
        ],
        'balanced' => [
            'label' => '⚖️ Équilibré',
            'description' => 'Défaut recommandé pour la plupart des applications. Bon compromis qualité/latence.',
            'generation_temperature' => 1.0,
            'generation_top_p' => 0.95,
            'generation_top_k' => 40,
            'generation_max_output_tokens' => 2000,
            'thinking_enabled' => false,
            'thinking_budget' => 0,
            'safety_enabled' => true,
            'safety_default_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
            'context_caching_enabled' => false,
        ],
        'creative' => [
            'label' => '🎨 Créatif',
            'description' => 'Haute variance pour générer du contenu original. Pour la création de texte.',
            'generation_temperature' => 1.5,
            'generation_top_p' => 0.98,
            'generation_top_k' => 50,
            'generation_max_output_tokens' => 3000,
            'thinking_enabled' => false,
            'thinking_budget' => 0,
            'safety_enabled' => false,
            'context_caching_enabled' => false,
        ],
        'smart' => [
            'label' => '🧠 Intelligent',
            'description' => 'Utilise le thinking natif pour un raisonnement approfondi. Plus lent mais plus exact.',
            'generation_temperature' => 1.0,
            'generation_top_p' => 0.95,
            'generation_top_k' => 40,
            'generation_max_output_tokens' => 2000,
            'thinking_enabled' => true,
            'thinking_budget' => 2000,
            'safety_enabled' => true,
            'safety_default_threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
            'context_caching_enabled' => false,
        ],
    ];

    /**
     * Get all available presets with their metadata.
     *
     * @return array Array of presets with label and description
     */
    public function getAvailablePresets(): array
    {
        $result = [];
        foreach (self::PRESETS as $id => $preset) {
            $result[$id] = [
                'label' => $preset['label'],
                'description' => $preset['description'],
            ];
        }
        return $result;
    }

    /**
     * Apply a preset to configuration data.
     *
     * @param string $presetId ID of the preset (fast, balanced, creative, smart)
     * @param array  $data     Current configuration data (will be modified)
     *
     * @return array Modified configuration data with preset values applied
     */
    public function applyPreset(string $presetId, array $data): array
    {
        if (!isset(self::PRESETS[$presetId])) {
            return $data;
        }

        $preset = self::PRESETS[$presetId];

        // Apply generation parameters
        $data['generation_temperature'] = $preset['generation_temperature'];
        $data['generation_top_p'] = $preset['generation_top_p'];
        $data['generation_top_k'] = $preset['generation_top_k'];
        $data['generation_max_output_tokens'] = $preset['generation_max_output_tokens'];

        // Apply thinking config
        $data['thinking_enabled'] = $preset['thinking_enabled'];
        $data['thinking_budget'] = $preset['thinking_budget'];

        // Apply safety config
        $data['safety_enabled'] = $preset['safety_enabled'];
        if (isset($preset['safety_default_threshold'])) {
            $data['safety_default_threshold'] = $preset['safety_default_threshold'];
        }

        // Apply caching config
        $data['context_caching_enabled'] = $preset['context_caching_enabled'];

        return $data;
    }

    /**
     * Get the parameters for a specific preset.
     *
     * @param string $presetId ID of the preset
     *
     * @return array|null Preset parameters or null if not found
     */
    public function getPreset(string $presetId): ?array
    {
        return self::PRESETS[$presetId] ?? null;
    }
}
