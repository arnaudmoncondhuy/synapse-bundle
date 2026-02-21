<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP de bas niveau pour l'API Google Gemini via Vertex AI.
 *
 * Credentials (project_id, region, service_account_json) chargés dynamiquement
 * depuis SynapseProvider en DB — aucune valeur YAML requise après l'installation.
 */
class GeminiClient implements LlmClientInterface
{
    private const VERTEX_URL        = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';
    private const VERTEX_STREAM_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:streamGenerateContent';

    // ── Config runtime (chargée depuis DB via applyDynamicConfig) ────────────
    private string $model                   = 'gemini-2.5-flash';
    private string $vertexProjectId         = '';
    private string $vertexRegion            = 'europe-west1';
    private bool   $thinkingEnabled         = true;
    private int    $thinkingBudget          = 1024;
    private bool   $safetySettingsEnabled   = false;
    private string $safetyDefaultThreshold  = 'BLOCK_MEDIUM_AND_ABOVE';
    private array  $safetyThresholds        = [];
    private float  $generationTemperature   = 1.0;
    private float  $generationTopP          = 0.95;
    private int    $generationTopK          = 40;
    private ?int   $generationMaxOutputTokens = null;
    private array  $generationStopSequences = [];
    private bool   $contextCachingEnabled   = false;
    private ?string $contextCachingId       = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private GoogleAuthService $googleAuthService,
        private ConfigProviderInterface $configProvider,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {}

    public function getProviderName(): string
    {
        return 'gemini';
    }

    /**
     * Génère du contenu via Vertex AI (mode synchrone).
     * Retourne un chunk normalisé au format Synapse.
     */
    public function generateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
    ): array {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $url = $this->buildVertexUrl(self::VERTEX_URL, $effectiveModel);
        $payload = $this->buildPayload($systemInstruction, $contents, $tools, $effectiveModel, $thinkingConfigOverride);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json'    => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $this->buildVertexHeaders(),
                'timeout' => 300,
            ]);

            return $this->normalizeChunk($response->toArray());
        } catch (\Throwable $e) {
            $this->handleException($e);
            return $this->emptyChunk();
        }
    }

    /**
     * Génère du contenu via Vertex AI (mode streaming).
     * Yield des chunks normalisés au format Synapse.
     *
     * @return \Generator<array>
     */
    public function streamGenerateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
    ): \Generator {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $url = $this->buildVertexUrl(self::VERTEX_STREAM_URL, $effectiveModel);
        $payload = $this->buildPayload($systemInstruction, $contents, $tools, $effectiveModel, $thinkingConfigOverride);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json'    => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $this->buildVertexHeaders(),
                'timeout' => 300,
            ]);

            $buffer = '';

            foreach ($this->httpClient->stream($response) as $chunk) {
                try {
                    $content = $chunk->getContent();
                } catch (\Throwable $e) {
                    $this->handleException($e);
                    return;
                }

                $buffer .= $content;

                // Parsing JSON Stream : format Vertex [ {obj1}, {obj2}, ... ]
                while (true) {
                    if (empty($buffer)) {
                        break;
                    }

                    $buffer = ltrim($buffer, " \t\n\r,");

                    if (str_starts_with($buffer, '[')) {
                        $buffer = substr($buffer, 1);
                        continue;
                    }
                    if (str_starts_with($buffer, ']')) {
                        $buffer = substr($buffer, 1);
                        continue;
                    }

                    if (empty($buffer)) {
                        break;
                    }

                    if (!str_starts_with($buffer, '{')) {
                        break;
                    }

                    $objEnd = $this->findObjectEnd($buffer);

                    if ($objEnd === null) {
                        break;
                    }

                    $jsonStr  = substr($buffer, 0, $objEnd + 1);
                    $jsonData = json_decode($jsonStr, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                        yield $this->normalizeChunk($jsonData);
                        $buffer = substr($buffer, $objEnd + 1);
                    } else {
                        $buffer = substr($buffer, 1);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Normalise un chunk brut Gemini vers le format Synapse canonical.
     */
    private function normalizeChunk(array $rawChunk): array
    {
        $normalized = $this->emptyChunk();

        if (isset($rawChunk['usageMetadata'])) {
            $u = $rawChunk['usageMetadata'];
            $normalized['usage'] = [
                'promptTokenCount'     => $u['promptTokenCount'] ?? 0,
                'candidatesTokenCount' => $u['candidatesTokenCount'] ?? 0,
                'thoughtsTokenCount'   => $u['thoughtsTokenCount'] ?? 0,
                'totalTokenCount'      => $u['totalTokenCount'] ?? 0,
            ];
        }

        $candidate = $rawChunk['candidates'][0] ?? [];

        if (isset($candidate['safetyRatings'])) {
            $normalized['safety_ratings'] = $candidate['safetyRatings'];
            foreach ($candidate['safetyRatings'] as $rating) {
                if ($rating['blocked'] ?? false) {
                    $normalized['blocked']          = true;
                    $normalized['blocked_category'] = $rating['category'] ?? 'UNKNOWN';
                    break;
                }
            }
        }

        $parts         = $candidate['content']['parts'] ?? [];
        $textParts     = [];
        $thinkingParts = [];

        foreach ($parts as $part) {
            $isThinking = isset($part['thought']) && true === $part['thought'];

            if ($isThinking) {
                if (isset($part['thinkingContent'])) {
                    $thinkingParts[] = $part['thinkingContent'];
                } elseif (isset($part['text'])) {
                    $thinkingParts[] = $part['text'];
                }
            } elseif (isset($part['text'])) {
                $textParts[] = $part['text'];
            } elseif (isset($part['functionCall'])) {
                $normalized['function_calls'][] = [
                    'name' => $part['functionCall']['name'],
                    'args' => $part['functionCall']['args'] ?? [],
                ];
            }
        }

        if (!empty($textParts)) {
            $normalized['text'] = implode('', $textParts);
        }

        if (!empty($thinkingParts)) {
            $normalized['thinking'] = implode('', $thinkingParts);
        }

        return $normalized;
    }

    private function emptyChunk(): array
    {
        return [
            'text'             => null,
            'thinking'         => null,
            'function_calls'   => [],
            'usage'            => [],
            'safety_ratings'   => [],
            'blocked'          => false,
            'blocked_category' => null,
        ];
    }

    /**
     * Applique la configuration dynamique depuis le ConfigProvider (DB).
     *
     * Lecture des credentials provider depuis provider_credentials :
     *   - project_id, region → vertexProjectId, vertexRegion
     *   - service_account_json → injecté dans GoogleAuthService
     */
    private function applyDynamicConfig(): void
    {
        $config = $this->configProvider->getConfig();

        if (!empty($config['model'])) {
            $this->model = $config['model'];
        }

        // Provider credentials (SynapseProvider en DB)
        if (!empty($config['provider_credentials'])) {
            $creds = $config['provider_credentials'];

            if (!empty($creds['project_id'])) {
                $this->vertexProjectId = $creds['project_id'];
            }
            if (!empty($creds['region'])) {
                $this->vertexRegion = $creds['region'];
            }
            if (!empty($creds['service_account_json'])) {
                $this->googleAuthService->setCredentialsJson($creds['service_account_json']);
            }
        }

        // Thinking
        if (isset($config['thinking'])) {
            $this->thinkingEnabled = $config['thinking']['enabled'] ?? $this->thinkingEnabled;
            $this->thinkingBudget  = $config['thinking']['budget'] ?? $this->thinkingBudget;
        }

        // Safety Settings
        if (isset($config['safety_settings'])) {
            $this->safetySettingsEnabled  = $config['safety_settings']['enabled'] ?? $this->safetySettingsEnabled;
            $this->safetyDefaultThreshold = $config['safety_settings']['default_threshold'] ?? $this->safetyDefaultThreshold;
            $this->safetyThresholds       = $config['safety_settings']['thresholds'] ?? $this->safetyThresholds;
        }

        // Generation Config
        if (isset($config['generation_config'])) {
            $gen = $config['generation_config'];
            $this->generationTemperature     = (float) ($gen['temperature'] ?? $this->generationTemperature);
            $this->generationTopP            = (float) ($gen['top_p'] ?? $this->generationTopP);
            $this->generationTopK            = (int) ($gen['top_k'] ?? $this->generationTopK);
            $this->generationMaxOutputTokens = $gen['max_output_tokens'] ?? $this->generationMaxOutputTokens;
            $this->generationStopSequences   = $gen['stop_sequences'] ?? $this->generationStopSequences;
        }

        // Context Caching
        if (isset($config['context_caching'])) {
            $this->contextCachingEnabled = $config['context_caching']['enabled'] ?? $this->contextCachingEnabled;
            $this->contextCachingId      = $config['context_caching']['cached_content_id'] ?? $this->contextCachingId;
        }
    }

    private function findObjectEnd(string $buffer): ?int
    {
        $len      = strlen($buffer);
        $depth    = 0;
        $inString = false;
        $escaped  = false;

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
                        return $i;
                    }
                }
            }
        }

        return null;
    }

    private function buildVertexUrl(string $template, string $model): string
    {
        $caps = $this->capabilityRegistry->getCapabilities($model);
        $finalModelId = $caps->modelId ?? $model;

        return sprintf(
            $template,
            $this->vertexRegion,
            $this->vertexProjectId,
            $this->vertexRegion,
            $finalModelId
        );
    }

    private function buildPayload(
        string $systemInstruction,
        array $contents,
        array $tools,
        string $effectiveModel,
        ?array $thinkingConfigOverride
    ): array {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => $contents,
        ];

        $generationConfig = $this->buildGenerationConfig($effectiveModel, $thinkingConfigOverride);
        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        if ($caps->safetySettings) {
            $safetySettings = $this->buildSafetySettings();
            if (!empty($safetySettings)) {
                $payload['safetySettings'] = $safetySettings;
            }
        } else {
            $payload['safetySettings'] = $this->buildSafetySettingsBlockNone();
        }

        if ($caps->contextCaching && $this->contextCachingEnabled && $this->contextCachingId) {
            $payload['cachedContent'] = $this->contextCachingId;
        }

        if (!empty($tools) && $caps->functionCalling) {
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

        // Normalize empty Map fields
        foreach ($payload['contents'] as &$message) {
            foreach ($message['parts'] as &$part) {
                if (isset($part['functionCall']['args']) && is_array($part['functionCall']['args']) && empty($part['functionCall']['args'])) {
                    $part['functionCall']['args'] = (object) [];
                }
                if (isset($part['functionResponse']['response']) && is_array($part['functionResponse']['response']) && empty($part['functionResponse']['response'])) {
                    $part['functionResponse']['response'] = (object) [];
                }
            }
        }

        return $payload;
    }

    private function buildVertexHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->googleAuthService->getAccessToken(),
            'Content-Type'  => 'application/json',
        ];
    }

    private function buildGenerationConfig(string $effectiveModel, ?array $thinkingConfigOverride = null): array
    {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        $config = [
            'temperature' => $this->generationTemperature,
            'topP'        => $this->generationTopP,
        ];

        if ($caps->topK) {
            $config['topK'] = $this->generationTopK;
        }

        if ($this->generationMaxOutputTokens !== null) {
            $config['maxOutputTokens'] = $this->generationMaxOutputTokens;
        }

        if (!empty($this->generationStopSequences)) {
            $config['stopSequences'] = $this->generationStopSequences;
        }

        $thinkingConfig = $thinkingConfigOverride ?? $this->buildThinkingConfig($effectiveModel);
        if ($thinkingConfig) {
            $config['thinkingConfig'] = $thinkingConfig;
        }

        return $config;
    }

    private function buildThinkingConfig(string $effectiveModel): ?array
    {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        if (!$this->thinkingEnabled || !$caps->thinking) {
            return null;
        }

        return [
            'thinkingBudget'  => $this->thinkingBudget,
            'includeThoughts' => true,
        ];
    }

    private function buildSafetySettings(): array
    {
        $categoryMapping = [
            'hate_speech'       => 'HARM_CATEGORY_HATE_SPEECH',
            'dangerous_content' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'harassment'        => 'HARM_CATEGORY_HARASSMENT',
            'sexually_explicit' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        ];

        if (!$this->safetySettingsEnabled) {
            return $this->buildSafetySettingsBlockNone();
        }

        $settings = [];
        foreach ($categoryMapping as $configKey => $apiCategory) {
            $threshold  = $this->safetyThresholds[$configKey] ?? $this->safetyDefaultThreshold;
            $settings[] = [
                'category'  => $apiCategory,
                'threshold' => $threshold,
            ];
        }

        return $settings;
    }

    private function buildSafetySettingsBlockNone(): array
    {
        $categories = [
            'HARM_CATEGORY_HATE_SPEECH',
            'HARM_CATEGORY_DANGEROUS_CONTENT',
            'HARM_CATEGORY_HARASSMENT',
            'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        ];

        return array_map(fn($cat) => ['category' => $cat, 'threshold' => 'BLOCK_NONE'], $categories);
    }

    private function handleException(\Throwable $e): void
    {
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
