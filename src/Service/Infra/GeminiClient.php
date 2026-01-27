<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP de bas niveau pour l'API Google Gemini via Vertex AI.
 *
 * Cette classe gère :
 * - L'authentification OAuth2 via GoogleAuthService.
 * - La communication HTTP (POST).
 * - La sérialisation des requêtes (Payload).
 * - La gestion sécurisée des erreurs.
 *
 * Elle n'a PAS de logique métier "Synapse" (pas d'historique, pas de persona),
 * elle ne fait que passer les plats à Google.
 */
class GeminiClient
{
    private const VERTEX_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';

    private ?ConfigProviderInterface $configProvider = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private GoogleAuthService $googleAuthService,
        private string $model = 'gemini-2.5-flash',
        private string $vertexProjectId,
        private string $vertexRegion = 'europe-west1',
        private bool $thinkingEnabled = true,
        private int $thinkingBudget = 1024,
        private bool $safetySettingsEnabled = false,
        private string $safetyDefaultThreshold = 'BLOCK_MEDIUM_AND_ABOVE',
        private array $safetyThresholds = [],
        private float $generationTemperature = 1.0,
        private float $generationTopP = 0.95,
        private int $generationTopK = 40,
        private ?int $generationMaxOutputTokens = null,
        private array $generationStopSequences = [],
        private bool $contextCachingEnabled = false,
        private ?string $contextCachingId = null,
        ?ConfigProviderInterface $configProvider = null,
    ) {
        $this->configProvider = $configProvider;
        $this->applyDynamicConfig();
    }

    /**
     * Applique la configuration dynamique depuis le ConfigProvider si présent.
     *
     * Le provider a la priorité sur les paramètres du constructeur.
     */
    private function applyDynamicConfig(): void
    {
        if (null === $this->configProvider) {
            return;
        }

        $config = $this->configProvider->getConfig();

        // Safety Settings
        if (isset($config['safety_settings'])) {
            $this->safetySettingsEnabled = $config['safety_settings']['enabled'] ?? $this->safetySettingsEnabled;
            $this->safetyDefaultThreshold = $config['safety_settings']['default_threshold'] ?? $this->safetyDefaultThreshold;
            $this->safetyThresholds = $config['safety_settings']['thresholds'] ?? $this->safetyThresholds;
        }

        // Generation Config
        if (isset($config['generation_config'])) {
            $this->generationTemperature = $config['generation_config']['temperature'] ?? $this->generationTemperature;
            $this->generationTopP = $config['generation_config']['top_p'] ?? $this->generationTopP;
            $this->generationTopK = $config['generation_config']['top_k'] ?? $this->generationTopK;
            $this->generationMaxOutputTokens = $config['generation_config']['max_output_tokens'] ?? $this->generationMaxOutputTokens;
            $this->generationStopSequences = $config['generation_config']['stop_sequences'] ?? $this->generationStopSequences;
        }

        // Context Caching
        if (isset($config['context_caching'])) {
            $this->contextCachingEnabled = $config['context_caching']['enabled'] ?? $this->contextCachingEnabled;
            $this->contextCachingId = $config['context_caching']['cached_content_id'] ?? $this->contextCachingId;
        }
    }

    /**
     * Génère du contenu via l'API Gemini sur Vertex AI.
     *
     * @param string      $systemInstruction      instructions systèmes (System Prompt)
     * @param array       $contents               Historique de la conversation au format Gemini API.
     *                                            Chaque item doit être un tableau `['role' => 'user|model', 'parts' => [...]]`.
     * @param array       $tools                  Définitions des outils (Function Declarations).
     *                                            Optionnel, permet au modèle de demander l'exécution de fonctions.
     * @param string|null $model                  modèle spécifique pour cette requête (prioritaire sur la config)
     * @param array|null  $thinkingConfigOverride Configuration thinking personnalisée (override la config par défaut)
     *
     * @return array La réponse brute de l'API (le premier candidat).
     *               Généralement un tableau contenant ['parts' => ...].
     *
     * @throws \RuntimeException Si l'appel API échoue (timeout, quota, 500, etc.).
     */
    public function generateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
    ): array {
        $effectiveModel = $model ?? $this->model;

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => $contents,
        ];

        // Build generation config (includes thinking config)
        $generationConfig = $this->buildGenerationConfig($thinkingConfigOverride);
        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        // Safety Settings
        $safetySettings = $this->buildSafetySettings();
        if (!empty($safetySettings)) {
            $payload['safetySettings'] = $safetySettings;
        }

        // Context Caching
        if ($this->contextCachingEnabled && $this->contextCachingId) {
            $payload['cachedContent'] = $this->contextCachingId;
        }

        // Tools
        if (!empty($tools)) {
            $firstTool = reset($tools);
            $isFlatFunctionList = is_array($firstTool)
                && isset($firstTool['name'])
                && !isset($firstTool['function_declarations']);

            if ($isFlatFunctionList) {
                $payload['tools'] = [
                    ['function_declarations' => $tools],
                ];
            } else {
                $payload['tools'] = $tools;
            }
        }

        // Build Vertex AI URL and headers
        $url = $this->buildVertexUrl($effectiveModel);
        $headers = $this->buildVertexHeaders();

        try {
            $options = [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $headers,
            ];

            $response = $this->httpClient->request('POST', $url, $options);
            $data = $response->toArray();

            return $data['candidates'][0]['content'] ?? [];
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if ($e instanceof HttpExceptionInterface) {
                try {
                    $errorBody = $e->getResponse()->getContent(false);
                    $message .= ' || Google Error: ' . $errorBody;
                } catch (\Throwable) {
                }
            }

            throw new \RuntimeException('Gemini API Error: ' . $message, 0, $e);
        }
    }

    private function buildVertexUrl(string $model): string
    {
        return sprintf(
            self::VERTEX_URL,
            $this->vertexRegion,
            $this->vertexProjectId,
            $this->vertexRegion,
            $model
        );
    }

    private function buildVertexHeaders(): array
    {
        $accessToken = $this->googleAuthService->getAccessToken();

        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Construit la configuration de génération complète (temperature, topP, topK, thinking, etc.).
     *
     * @param array|null $thinkingConfigOverride Configuration thinking personnalisée (override la config par défaut)
     * @return array Configuration de génération
     */
    private function buildGenerationConfig(?array $thinkingConfigOverride = null): array
    {
        $config = [
            'temperature' => $this->generationTemperature,
            'topP' => $this->generationTopP,
            'topK' => $this->generationTopK,
        ];

        if ($this->generationMaxOutputTokens !== null) {
            $config['maxOutputTokens'] = $this->generationMaxOutputTokens;
        }

        if (!empty($this->generationStopSequences)) {
            $config['stopSequences'] = $this->generationStopSequences;
        }

        // Add thinking config if enabled
        $thinkingConfig = $thinkingConfigOverride ?? $this->buildThinkingConfig();
        if ($thinkingConfig) {
            $config['thinkingConfig'] = $thinkingConfig;
        }

        return $config;
    }

    /**
     * Construit la configuration de thinking natif.
     *
     * @return array|null Configuration ou null si désactivé
     */
    private function buildThinkingConfig(): ?array
    {
        if (!$this->thinkingEnabled) {
            return null;
        }

        return [
            'thinkingBudget' => $this->thinkingBudget,
            'includeThoughts' => true, // Retrieve thought summaries in response
        ];
    }

    /**
     * Construit les paramètres de sécurité (safety filters).
     *
     * @return array Liste des safetySettings pour chaque catégorie
     */
    private function buildSafetySettings(): array
    {
        if (!$this->safetySettingsEnabled) {
            return [];
        }

        $categoryMapping = [
            'hate_speech' => 'HARM_CATEGORY_HATE_SPEECH',
            'dangerous_content' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'harassment' => 'HARM_CATEGORY_HARASSMENT',
            'sexually_explicit' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        ];

        $settings = [];
        foreach ($categoryMapping as $configKey => $apiCategory) {
            $threshold = $this->safetyThresholds[$configKey] ?? $this->safetyDefaultThreshold;
            $settings[] = [
                'category' => $apiCategory,
                'threshold' => $threshold,
            ];
        }

        return $settings;
    }
}
