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
 * - Le Streaming (Server-Sent Events / JSON Stream).
 *
 * Elle n'a PAS de logique métier "Synapse" (pas d'historique, pas de persona),
 * elle ne fait que passer les plats à Google.
 */
class GeminiClient
{
    private const VERTEX_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';
    private const VERTEX_STREAM_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:streamGenerateContent';

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
     * Génère du contenu via l'API Gemini sur Vertex AI (Mode Synchrone).
     *
     * @param string      $systemInstruction      instructions systèmes (System Prompt)
     * @param array       $contents               Historique de la conversation au format Gemini API.
     * @param array       $tools                  Définitions des outils (Function Declarations).
     * @param string|null $model                  modèle spécifique pour cette requête.
     * @param array|null  $thinkingConfigOverride Configuration thinking personnalisée.
     *
     * @return array La réponse brute de l'API (le premier candidat).
     *
     * @throws \RuntimeException Si l'appel API échoue.
     */
    public function generateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
    ): array {
        $effectiveModel = $model ?? $this->model;
        $url = $this->buildVertexUrl(self::VERTEX_URL, $effectiveModel);
        
        $payload = $this->buildPayload($systemInstruction, $contents, $tools, $thinkingConfigOverride);
        $headers = $this->buildVertexHeaders();

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $headers,
            ]);

            return $response->toArray(); // Attente bloquante
        } catch (\Throwable $e) {
            $this->handleException($e);
            return []; // Unreachable with handleException throwing
        }
    }

    /**
     * Génère du contenu via l'API Gemini sur Vertex AI (Mode Streaming).
     *
     * Retourne un générateur qui yield chaque chunk de réponse JSON décodé
     * au fur et à mesure de leur réception.
     *
     * @param string      $systemInstruction      instructions systèmes
     * @param array       $contents               Historique
     * @param array       $tools                  Outils
     * @param string|null $model                  Modèle
     * @param array|null  $thinkingConfigOverride Config thinking
     *
     * @return \Generator<array> Yield chaque chunk JSON (tableau associatif).
     */
    public function streamGenerateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
    ): \Generator {
        $effectiveModel = $model ?? $this->model;
        $url = $this->buildVertexUrl(self::VERTEX_STREAM_URL, $effectiveModel);

        $payload = $this->buildPayload($systemInstruction, $contents, $tools, $thinkingConfigOverride);
        $headers = $this->buildVertexHeaders();

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $headers,
            ]); // Ne bloque pas, la requête est lancée

            $buffer = '';
            $depth = 0;
            $inString = false;
            $escape = false;

            // Lecture du flux chunk par chunk
            foreach ($this->httpClient->stream($response) as $chunk) {
                // Check for transport errors immediately
                if ($chunk->isLast()) {
                    // Check if request failed at HTTP level
                   // $chunk->getContent() will throw if status code is error and we check it.
                   // But stream() yields chunks.
                   // We trust Symfony HttpClient to handle status checks if we access headers/content?
                   // Actually stream() yields ChunkInterface.
                   // If we call getContent() on a timeout/error chunk it throws.
                }
                
                try {
                    $content = $chunk->getContent(); // Peut bloquer un tout petit peu le temps d'avoir des paquets
                } catch (\Throwable $e) {
                    $this->handleException($e);
                    return; // Unreachable
                }

                // Append content to buffer
                $buffer .= $content;

                // Parsing JSON Stream "à la main" pour extraire les objets complets du tableau
                // Le format est : [ {obj1}, {obj2}, ... ]
                // On cherche à extraire les objets { ... } de niveau 1.
                
                // Note : Faire un parser JSON complet est complexe.
                // Simplification robuste : Vertex renvoie des objets séparés par des virgules.
                // On va scanner le buffer pour trouver des objets complets.
                
                while (true) {
                    // Nettoyage début : sauter [ et , et whitespace
                    if (empty($buffer)) break;

                    $buffer = ltrim($buffer, " \t\n\r,");
                    
                    if (str_starts_with($buffer, '[')) {
                        $buffer = substr($buffer, 1);
                        continue;
                    }
                    if (str_starts_with($buffer, ']')) {
                        $buffer = substr($buffer, 1);
                        continue;
                    }

                    // Si vide après nettoyage, on attend plus de données
                    if (empty($buffer)) break;
                    
                    // On suppose qu'on est au début d'un objet '{'
                    if (!str_starts_with($buffer, '{')) {
                        // Si on a du "garbage" on le vire (ne devrait pas arriver avec Vertex)
                        // ou on attend la suite si C'est incomplet ?
                        // Vertex ne coupe pas n'importe comment en théorie, mais le réseau oui.
                        // Si ça ne commence pas par {, on peut avoir un problème.
                        // Mais ça peut être la fin d'un array ']'
                         break; // Wait for more data
                    }

                    // Tenter de trouver la fin de l'objet via json_decode sur des substrings ? Trop lourd.
                    // On compte les accolades.
                    $objEnd = $this->findObjectEnd($buffer);
                    
                    if ($objEnd === null) {
                        break; // Objet pas encore complet, attendre le prochain chunk HTTP
                    }

                    // On a un objet complet de 0 à $objEnd (inclus)
                    $jsonStr = substr($buffer, 0, $objEnd + 1);
                    $jsonData = json_decode($jsonStr, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                        yield $jsonData;
                        
                        // Avancer le buffer
                        $buffer = substr($buffer, $objEnd + 1);
                    } else {
                        // Parse error ? On log ou on ignore ?
                        // On suppose que c'est un glitch, on avance d'un char pour éviter boucle inf
                         $buffer = substr($buffer, 1);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Trouve l'index de fermeture de l'objet JSON courant (balance des accolades).
     * Simple et naïf mais fonctionne pour les flux Vertex standards.
     * Gère les accolades dans les chaînes de caractères.
     */
    private function findObjectEnd(string $buffer): ?int
    {
        $len = strlen($buffer);
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $buffer[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if (!$inString) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $i; // Found end of object
                    }
                }
            }
        }

        return null; // Not found yet
    }

    private function buildVertexUrl(string $template, string $model): string
    {
        return sprintf(
            $template,
            $this->vertexRegion,
            $this->vertexProjectId,
            $this->vertexRegion,
            $model
        );
    }

    private function buildPayload(
        string $systemInstruction,
        array $contents,
        array $tools,
        ?array $thinkingConfigOverride
    ): array {
        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => $contents,
        ];

        // Build generation config
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

        return $payload;
    }

    private function buildVertexHeaders(): array
    {
        $accessToken = $this->googleAuthService->getAccessToken();

        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ];
    }

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

    private function buildThinkingConfig(): ?array
    {
        if (!$this->thinkingEnabled) {
            return null;
        }

        return [
            'thinkingBudget' => $this->thinkingBudget,
            'includeThoughts' => true,
        ];
    }

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

    private function handleException(\Throwable $e): void
    {
        $message = $e->getMessage();

        if ($e instanceof HttpExceptionInterface) {
            try {
                $errorBody = $e->getResponse()->getContent(false); // false = don't throw
                $message .= ' || Google Error: ' . $errorBody;
            } catch (\Throwable) {
            }
        }

        throw new \RuntimeException('Gemini API Error: ' . $message, 0, $e);
    }
}
