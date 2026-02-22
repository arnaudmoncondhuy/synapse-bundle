<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Chat;

use ArnaudMoncondhuy\SynapseBundle\Shared\Model\ModelCapabilities;
use Symfony\Component\Yaml\Yaml;

/**
 * Registre des profils de capacités par modèle LLM.
 *
 * Charge les capacités depuis des fichiers YAML situés dans Resources/config/models/.
 * Permet à chaque client (GeminiClient, OvhAiClient…) de savoir quels
 * paramètres envoyer à l'API.
 */
class ModelCapabilityRegistry
{
    /** @var array<string, array> Cache local des modèles chargés */
    private array $models = [];

    public function __construct()
    {
        $this->loadModels();
    }

    /**
     * Charge tous les fichiers YAML du dossier de configuration des modèles.
     */
    private function loadModels(): void
    {
        $configDir = __DIR__ . '/../Resources/config/models';
        if (!is_dir($configDir)) {
            return;
        }

        $files = glob($configDir . '/*.yaml');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            try {
                $config = Yaml::parseFile($file);
                if (isset($config['models']) && is_array($config['models'])) {
                    $this->models = array_merge($this->models, $config['models']);
                }
            } catch (\Throwable $e) {
                // En cas d'erreur sur un fichier, on continue pour ne pas bloquer tout le registre
                // Dans un contexte de prod, on pourrait logguer ici.
            }
        }
    }

    /**
     * Capacités par défaut pour les modèles non référencés.
     */
    private const DEFAULTS = [
        'provider'        => 'unknown',
        'thinking'        => false,
        'safety_settings' => false,
        'top_k'           => false,
        'context_caching' => false,
        'function_calling' => true,
        'streaming'       => true,
        'system_prompt'   => true,
        'pricing_input'   => null,
        'pricing_output'  => null,
    ];

    /**
     * Retourne le profil de capacités d'un modèle.
     */
    public function getCapabilities(string $model): ModelCapabilities
    {
        $data = $this->models[$model] ?? self::DEFAULTS;

        return new ModelCapabilities(
            model: $model,
            provider: $data['provider'],
            thinking: $data['thinking'] ?? false,
            safetySettings: $data['safety_settings'] ?? false,
            topK: $data['top_k'] ?? false,
            contextCaching: $data['context_caching'] ?? false,
            functionCalling: $data['function_calling'] ?? true,
            streaming: $data['streaming'] ?? true,
            systemPrompt: $data['system_prompt'] ?? true,
            pricingInput: isset($data['pricing_input']) ? (float) $data['pricing_input'] : null,
            pricingOutput: isset($data['pricing_output']) ? (float) $data['pricing_output'] : null,
            modelId: $data['model_id'] ?? null,
        );
    }

    public function supports(string $model, string $capability): bool
    {
        return $this->getCapabilities($model)->supports($capability);
    }

    /**
     * Liste tous les modèles référencés dans le registre.
     * @return string[]
     */
    public function getKnownModels(): array
    {
        return array_keys($this->models);
    }

    /**
     * Liste les modèles disponibles pour un provider donné.
     * @return string[]
     */
    public function getModelsForProvider(string $provider): array
    {
        return array_keys(array_filter(
            $this->models,
            fn(array $caps) => ($caps['provider'] ?? '') === $provider
        ));
    }

    public function isKnownModel(string $model): bool
    {
        return isset($this->models[$model]);
    }
}
