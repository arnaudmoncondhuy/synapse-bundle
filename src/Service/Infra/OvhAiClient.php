<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Infra;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\ModelCapabilityRegistry;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP pour OVH AI Endpoints (100% compatible API OpenAI).
 *
 * Convertit le format interne Synapse (canonical Gemini-like) vers le format
 * OpenAI Chat Completions, et normalise la réponse vers le format Synapse.
 *
 * Format entrant (Synapse canonical) :
 *   ['role' => 'user'|'model'|'function', 'parts' => [...]]
 *
 * Format sortant (chunks normalisés) :
 *   ['text' => string|null, 'thinking' => null, 'function_calls' => [...], ...]
 *
 * Endpoint   : https://oai.endpoints.kepler.ai.cloud.ovh.net/v1
 * Auth       : Bearer {api_key}
 * Streaming  : SSE (data: {...}\n, terminé par data: [DONE])
 * Tool calls : format OpenAI (tool_calls / role tool)
 */
class OvhAiClient implements LlmClientInterface
{
    private string $model         = 'Gpt-oss-20b';
    private string $apiKey        = '';
    private string $endpoint      = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1';
    private float  $temperature   = 1.0;
    private float  $topP          = 0.95;
    private ?int   $maxTokens     = null;
    private array  $stopSequences = [];
    private bool   $thinkingEnabled = false;
    private ?int   $thinkingBudget = null;
    private string $reasoningEffort = 'high';  // high, medium, low, minimal

    public function __construct(
        private HttpClientInterface $httpClient,
        private ConfigProviderInterface $configProvider,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {
    }

    public function getProviderName(): string
    {
        return 'ovh';
    }

    /**
     * Génère du contenu en mode streaming (SSE).
     * Yield des chunks normalisés au format Synapse.
     */
    public function streamGenerateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        $messages = $this->toOpenAiMessages($systemInstruction, $contents, $caps->systemPrompt);
        $payload = $this->buildPayload($effectiveModel, $messages, $tools, $caps, true);

        // Capture les paramètres réellement envoyés (après filtrage par ModelCapabilityRegistry)
        $debugOut['actual_request_params'] = [
            'model'              => $effectiveModel,
            'provider'           => 'ovh',
            'temperature'        => $this->temperature,
            'top_p'              => $this->topP,
            'top_k'              => null,  // OVH n'expose pas topK
            'max_output_tokens'  => $this->maxTokens,
            'thinking_enabled'   => $this->thinkingEnabled,
            'thinking_budget'    => $this->thinkingBudget,
            'reasoning_effort'   => $this->thinkingEnabled ? $this->reasoningEffort : null,
            'safety_enabled'     => false,  // OVH n'a pas de sécurité native
            'tools_sent'         => !empty($tools) && $caps->functionCalling,
            'system_prompt_sent' => $caps->systemPrompt && !empty($systemInstruction),
            'context_caching'    => false,  // OVH n'a pas de context caching
        ];
        $debugOut['raw_request_body'] = $payload;

        try {
            $response = $this->httpClient->request('POST', rtrim($this->endpoint, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => $payload,
                'timeout' => 300,
                'buffer'  => false,
            ]);

            // Accumulate tool call arguments across chunks (tool calls are streamed incrementally)
            $toolCallsAccumulator = []; // index => ['id' => '', 'name' => '', 'args' => '']
            $buffer = '';
            $rawApiChunks = []; // Capturer tous les chunks bruts de l'API pour le debug

            foreach ($this->httpClient->stream($response) as $chunk) {
                try {
                    $buffer .= $chunk->getContent();
                } catch (\Throwable $e) {
                    $this->handleException($e);
                    return;
                }

                // Process complete SSE lines from buffer
                while (($nlPos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nlPos);
                    $buffer = substr($buffer, $nlPos + 1);
                    $line = rtrim($line, "\r");

                    if ($line === 'data: [DONE]') {
                        // Safety net: flush remaining accumulated tool calls if any
                        if (!empty($toolCallsAccumulator)) {
                            yield $this->buildToolCallChunk($toolCallsAccumulator);
                        }
                        continue;
                    }

                    if ($line === '' || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $jsonStr = substr($line, 6); // Remove 'data: '
                    $data = json_decode($jsonStr, true);
                    if (!is_array($data)) {
                        continue;
                    }

                    // Capturer les chunks bruts AVANT normalisation (vrai debug)
                    $rawApiChunks[] = $data;

                    $result = $this->processChunk($data, $toolCallsAccumulator);
                    if ($result !== null) {
                        yield $result;
                    }
                }
            }

            // Handle any remaining buffer content (edge case)
            $remaining = trim($buffer);
            if ($remaining !== '' && str_starts_with($remaining, 'data: ') && $remaining !== 'data: [DONE]') {
                $jsonStr = substr($remaining, 6);
                $data = json_decode($jsonStr, true);
                if (is_array($data)) {
                    // Capturer le dernier chunk brut aussi
                    $rawApiChunks[] = $data;

                    $result = $this->processChunk($data, $toolCallsAccumulator);
                    if ($result !== null) {
                        yield $result;
                    }
                }
            }

            // Passer les chunks bruts de l'API au debug (VRAI brut, avant normalisation)
            $debugOut['raw_api_chunks'] = $rawApiChunks;
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Génère du contenu en mode synchrone (non-streaming).
     */
    public function generateContent(
        string $systemInstruction,
        array $contents,
        array $tools = [],
        ?string $model = null,
        ?array $thinkingConfigOverride = null,
        array &$debugOut = [],
    ): array {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        $messages = $this->toOpenAiMessages($systemInstruction, $contents, $caps->systemPrompt);
        $payload = $this->buildPayload($effectiveModel, $messages, $tools, $caps, false);

        // Capture les paramètres réellement envoyés (après filtrage par ModelCapabilityRegistry)
        $debugOut['actual_request_params'] = [
            'model'              => $effectiveModel,
            'provider'           => 'ovh',
            'temperature'        => $this->temperature,
            'top_p'              => $this->topP,
            'top_k'              => null,  // OVH n'expose pas topK
            'max_output_tokens'  => $this->maxTokens,
            'thinking_enabled'   => $this->thinkingEnabled,
            'thinking_budget'    => $this->thinkingBudget,
            'reasoning_effort'   => $this->thinkingEnabled ? $this->reasoningEffort : null,
            'safety_enabled'     => false,  // OVH n'a pas de sécurité native
            'tools_sent'         => !empty($tools) && $caps->functionCalling,
            'system_prompt_sent' => $caps->systemPrompt && !empty($systemInstruction),
            'context_caching'    => false,  // OVH n'a pas de context caching
        ];
        $debugOut['raw_request_body'] = $payload;

        try {
            $response = $this->httpClient->request('POST', rtrim($this->endpoint, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => $payload,
                'timeout' => 300,
            ]);

            $data = $response->toArray();

            // Passer la réponse brute de l'API au debug (VRAI brut, avant normalisation)
            $debugOut['raw_api_response'] = $data;

            return $this->normalizeCompletionResponse($data);
        } catch (\Throwable $e) {
            $this->handleException($e);
            return $this->emptyChunk();
        }
    }

    /**
     * Traite un chunk SSE et met à jour l'accumulateur de tool calls.
     * Retourne un chunk normalisé, ou null si le chunk ne contient rien d'utile.
     */
    private function processChunk(array $data, array &$toolCallsAccumulator): ?array
    {
        $normalized = $this->emptyChunk();

        // Usage metadata (usually in the last chunk with stream_options.include_usage)
        if (isset($data['usage']) && is_array($data['usage'])) {
            $u = $data['usage'];
            // OVH peut fournir reasoning_tokens sous completion_tokens_details (imbriqué)
            $reasoningTokens = 0;
            if (isset($u['completion_tokens_details']) && is_array($u['completion_tokens_details'])) {
                $reasoningTokens = $u['completion_tokens_details']['reasoning_tokens'] ?? 0;
            }
            $normalized['usage'] = [
                'promptTokenCount'     => $u['prompt_tokens'] ?? 0,
                'candidatesTokenCount' => $u['completion_tokens'] ?? 0,
                'thoughtsTokenCount'   => $reasoningTokens,
                'totalTokenCount'      => $u['total_tokens'] ?? 0,
            ];
        }

        $choice = $data['choices'][0] ?? null;
        if ($choice === null) {
            // Only yield if we have usage data
            return !empty($normalized['usage']) ? $normalized : null;
        }

        $delta = $choice['delta'] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;

        // Text content
        if (isset($delta['content']) && $delta['content'] !== null && $delta['content'] !== '') {
            $normalized['text'] = $delta['content'];
        }

        // Reasoning/Thinking content (OpenAI compatible format)
        // OVH may return reasoning in 'reasoning' or 'reasoning_content' fields
        if (isset($delta['reasoning']) && $delta['reasoning'] !== null && $delta['reasoning'] !== '') {
            $normalized['thinking'] = $delta['reasoning'];
        } elseif (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== null && $delta['reasoning_content'] !== '') {
            $normalized['thinking'] = $delta['reasoning_content'];
        }

        // Tool calls (streamed incrementally — name in first chunk, args accumulated)
        if (isset($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tc) {
                $idx = $tc['index'] ?? 0;
                if (!isset($toolCallsAccumulator[$idx])) {
                    $toolCallsAccumulator[$idx] = ['id' => '', 'name' => '', 'args' => ''];
                }
                if (!empty($tc['id'])) {
                    $toolCallsAccumulator[$idx]['id'] = $tc['id'];
                }
                if (!empty($tc['function']['name'] ?? '')) {
                    $toolCallsAccumulator[$idx]['name'] = $tc['function']['name'];
                }
                if (isset($tc['function']['arguments'])) {
                    $toolCallsAccumulator[$idx]['args'] .= $tc['function']['arguments'];
                }
            }
        }

        // When finish_reason is 'tool_calls', all tool call chunks have been received
        if ($finishReason === 'tool_calls' && !empty($toolCallsAccumulator)) {
            $toolChunk = $this->buildToolCallChunk($toolCallsAccumulator);
            // Merge usage if present in the same chunk
            if (!empty($normalized['usage'])) {
                $toolChunk['usage'] = $normalized['usage'];
            }
            return $toolChunk;
        }

        // Skip truly empty chunks (no text, no thinking, no usage, no tool data)
        $hasContent = $normalized['text'] !== null
                   || $normalized['thinking'] !== null
                   || !empty($normalized['usage']);
        return $hasContent ? $normalized : null;
    }

    /**
     * Construit un chunk normalisé à partir des tool calls accumulés.
     * Vide l'accumulateur.
     */
    private function buildToolCallChunk(array &$toolCallsAccumulator): array
    {
        $chunk = $this->emptyChunk();

        ksort($toolCallsAccumulator);
        foreach ($toolCallsAccumulator as $tc) {
            $args = json_decode($tc['args'], true) ?? [];
            $chunk['function_calls'][] = [
                'name' => $tc['name'],
                'args' => $args,
            ];
        }

        $toolCallsAccumulator = [];

        return $chunk;
    }

    /**
     * Convertit les messages Synapse canonical en messages OpenAI.
     *
     * Mapping des rôles :
     *   - 'user'     → 'user'
     *   - 'model'    → 'assistant'
     *   - 'function' → 'tool'
     *
     * Mapping des parts :
     *   - text           → content
     *   - functionCall   → tool_calls (avec IDs séquentiels)
     *   - functionResponse → role tool avec tool_call_id
     */
    private function toOpenAiMessages(string $systemInstruction, array $contents, bool $includeSystem): array
    {
        $messages = [];

        if ($includeSystem && $systemInstruction !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }

        // Maps function names → tool_call_ids for matching function responses to their calls
        $toolCallIdMap = [];

        foreach ($contents as $message) {
            $role = $message['role'] ?? '';
            $parts = $message['parts'] ?? [];

            switch ($role) {
                case 'user':
                    $text = implode('', array_column(
                        array_filter($parts, fn ($p) => isset($p['text'])),
                        'text'
                    ));
                    $messages[] = ['role' => 'user', 'content' => $text];
                    break;

                case 'model':
                    $textParts = [];
                    $toolCalls = [];
                    $tcIndex = count($toolCallIdMap); // Keep IDs globally unique

                    foreach ($parts as $part) {
                        if (isset($part['text'])) {
                            $textParts[] = $part['text'];
                        } elseif (isset($part['functionCall'])) {
                            $fcName = $part['functionCall']['name'];
                            $fcArgs = $part['functionCall']['args'] ?? [];

                            // Generate a deterministic-ish tool_call_id
                            $tcId = 'call_' . substr(md5($fcName . $tcIndex), 0, 12);
                            $toolCallIdMap[$fcName] = $tcId;
                            $tcIndex++;

                            $toolCalls[] = [
                                'id'       => $tcId,
                                'type'     => 'function',
                                'function' => [
                                    'name'      => $fcName,
                                    'arguments' => json_encode($fcArgs, JSON_UNESCAPED_UNICODE),
                                ],
                            ];
                        }
                    }

                    $assistantMsg = [
                        'role'    => 'assistant',
                        'content' => !empty($textParts) ? implode('', $textParts) : null,
                    ];
                    if (!empty($toolCalls)) {
                        $assistantMsg['tool_calls'] = $toolCalls;
                    }
                    $messages[] = $assistantMsg;
                    break;

                case 'function':
                    foreach ($parts as $part) {
                        if (isset($part['functionResponse'])) {
                            $fnName   = $part['functionResponse']['name'];
                            $response = $part['functionResponse']['response'] ?? [];

                            // Match to the tool_call_id used when the call was made
                            $tcId = $toolCallIdMap[$fnName] ?? ('call_' . $fnName);

                            $messages[] = [
                                'role'         => 'tool',
                                'tool_call_id' => $tcId,
                                'content'      => is_string($response) ? $response : json_encode($response, JSON_UNESCAPED_UNICODE),
                            ];
                        }
                    }
                    break;
            }
        }

        return $messages;
    }

    /**
     * Convertit les déclarations d'outils Synapse en format OpenAI.
     */
    private function toOpenAiTools(array $tools): array
    {
        return array_map(fn (array $tool) => [
            'type'     => 'function',
            'function' => [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $tool['parameters'],
            ],
        ], $tools);
    }

    /**
     * Construit le payload de requête OpenAI.
     */
    private function buildPayload(string $model, array $messages, array $tools, $caps, bool $stream): array
    {
        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $this->temperature,
            'top_p'       => $this->topP,
            'stream'      => $stream,
        ];

        if ($stream) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        if ($this->maxTokens !== null) {
            $payload['max_tokens'] = $this->maxTokens;
        }

        if (!empty($this->stopSequences)) {
            $payload['stop'] = $this->stopSequences;
        }

        if (!empty($tools) && $caps->functionCalling) {
            $payload['tools'] = $this->toOpenAiTools($tools);
        }

        // Ajouter la réflexion/reasoning si activée (paramètre OVH: reasoning_effort)
        if ($this->thinkingEnabled) {
            // Les valeurs possibles sont: "high", "medium", "low", "minimal"
            $payload['reasoning_effort'] = $this->reasoningEffort;
        }

        return $payload;
    }

    /**
     * Normalise une réponse synchrone complète (non-streaming).
     */
    private function normalizeCompletionResponse(array $data): array
    {
        $normalized = $this->emptyChunk();

        if (isset($data['usage']) && is_array($data['usage'])) {
            $u = $data['usage'];
            // OVH peut fournir reasoning_tokens sous completion_tokens_details (imbriqué)
            $reasoningTokens = 0;
            if (isset($u['completion_tokens_details']) && is_array($u['completion_tokens_details'])) {
                $reasoningTokens = $u['completion_tokens_details']['reasoning_tokens'] ?? 0;
            }
            $normalized['usage'] = [
                'promptTokenCount'     => $u['prompt_tokens'] ?? 0,
                'candidatesTokenCount' => $u['completion_tokens'] ?? 0,
                'thoughtsTokenCount'   => $reasoningTokens,
                'totalTokenCount'      => $u['total_tokens'] ?? 0,
            ];
        }

        $choice = $data['choices'][0] ?? null;
        if ($choice === null) {
            return $normalized;
        }

        $message = $choice['message'] ?? [];

        if (!empty($message['content'])) {
            $normalized['text'] = $message['content'];
        }

        // OVH retourne le reasoning dans message.reasoning_content (mode synchrone)
        if (!empty($message['reasoning_content'])) {
            $normalized['thinking'] = $message['reasoning_content'];
        }

        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
                $normalized['function_calls'][] = [
                    'name' => $tc['function']['name'],
                    'args' => $args,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Retourne un chunk normalisé vide (toutes les valeurs par défaut).
     */
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
     * Credentials provider (api_key, endpoint) lus depuis provider_credentials.
     * Les paramètres OVH-incompatibles (top_k, thinking, safety) sont ignorés.
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

            if (!empty($creds['api_key'])) {
                $this->apiKey = $creds['api_key'];
            }
            if (!empty($creds['endpoint'])) {
                $this->endpoint = $creds['endpoint'];
            }
        }

        // Generation Config
        if (isset($config['generation_config'])) {
            $gen = $config['generation_config'];
            $this->temperature   = (float) ($gen['temperature'] ?? $this->temperature);
            $this->topP          = (float) ($gen['top_p'] ?? $this->topP);
            $this->maxTokens     = $gen['max_output_tokens'] ?? $this->maxTokens;
            $this->stopSequences = $gen['stop_sequences'] ?? $this->stopSequences;
            // top_k, safety_settings, context_caching ignorés pour OVH
        }

        // Réflexion/Thinking (stocké séparément dans config)
        if (isset($config['thinking'])) {
            $thinking = $config['thinking'];
            $this->thinkingEnabled = (bool) ($thinking['enabled'] ?? false);
            $this->thinkingBudget  = (int) ($thinking['budget'] ?? null);
            $this->reasoningEffort = (string) ($thinking['reasoning_effort'] ?? 'high');
        }
    }

    private function handleException(\Throwable $e): void
    {
        $message = $e->getMessage();

        if ($e instanceof HttpExceptionInterface) {
            try {
                $errorBody = $e->getResponse()->getContent(false);
                $message .= ' || OVH Error: ' . $errorBody;
            } catch (\Throwable) {
            }
        }

        throw new \RuntimeException('OVH AI API Error: ' . $message, 0, $e);
    }
}
