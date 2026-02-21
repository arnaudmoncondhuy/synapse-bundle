<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Entity\DebugLog;
use ArnaudMoncondhuy\SynapseBundle\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseBundle\Repository\DebugLogRepository;
use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Orchestrateur principal des échanges conversationnels avec l'IA.
 *
 * Cette classe coordonne :
 * 1. La construction du contexte (Prompt Builder).
 * 2. La sélection du client LLM actif (LlmClientRegistry).
 * 3. La communication via streaming normalisé.
 * 4. L'exécution dynamique des outils (Function Calling).
 * 5. La boucle de réflexion multi-tours avec l'IA.
 *
 * Les chunks yielded par les clients LLM sont au format Synapse normalisé :
 *   ['text' => string|null, 'thinking' => string|null, 'function_calls' => [...],
 *    'usage' => [...], 'safety_ratings' => [...], 'blocked' => bool, 'blocked_category' => string|null]
 */
class ChatService
{
    private const MAX_TURNS = 5;

    public function __construct(
        private LlmClientRegistry $llmRegistry,
        private PromptBuilder $promptBuilder,
        private iterable $tools,
        private CacheInterface $cache,
        private ConfigProviderInterface $configProvider,
        private EntityManagerInterface $em,
        private DebugLogRepository $debugLogRepo,
    ) {
    }

    /**
     * Traite un message utilisateur et retourne la réponse de l'IA (après exécution d'outils si nécessaire).
     *
     * @param string        $message
     * @param array         $options
     * @param callable|null $onStatusUpdate Function(string $message, string $step): void
     * @param callable|null $onToken        Function(string $token): void
     *
     * @return array
     */
    public function ask(
        string $message,
        array $options = [],
        ?callable $onStatusUpdate = null,
        ?callable $onToken = null
    ): array {
        $isStateless = $options['stateless'] ?? false;

        if (empty($message) && ($options['reset_conversation'] ?? false)) {
            return ['answer' => '', 'debug' => null];
        }

        // Build context
        $personaKey = $options['persona'] ?? null;
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($personaKey);

        // Tools Handling
        if (isset($options['tools']) && is_array($options['tools'])) {
            $toolDefinitions = $options['tools'];
        } else {
            $toolDefinitions = $this->buildToolDefinitions();
        }

        // Load History
        if ($isStateless) {
            $contents = [
                ['role' => 'user', 'parts' => [['text' => $message]]],
            ];
            $rawHistory = [];
        } else {
            $rawHistory = $options['history'] ?? [];
            $contents = $this->sanitizeHistoryForNewTurn($rawHistory);
            if (!empty($message)) {
                $contents[] = ['role' => 'user', 'parts' => [['text' => TextUtil::sanitizeUtf8($message)]]];
            }
        }

        if ($onStatusUpdate) {
            $onStatusUpdate('Analyse de la demande...', 'thinking');
        }

        $config = $this->configProvider->getConfig();

        // Support d'un preset spécifique passé en option (pour le test des presets)
        // Permet de tester un preset sans le rendre actif en DB
        $presetOverride = $options['preset'] ?? null;
        if ($presetOverride instanceof SynapsePreset) {
            $config = $this->configProvider->getConfigForPreset($presetOverride);
            $this->configProvider->setOverride($config);
        }

        $effectiveModel = $config['model'] ?? 'gemini-2.5-flash';

        // Check global debug mode (unless explicitly disabled in options)
        $globalDebugMode = $config['debug_mode'] ?? false;
        $debugMode = ($options['debug'] ?? false) || ($globalDebugMode && ($options['debug'] !== false));

        // Check if streaming is enabled (from preset)
        $streamingEnabled = $config['streaming_enabled'] ?? true;

        $activeClient = $this->llmRegistry->getClient();

        $debugAccumulator = [
            'model'          => $effectiveModel,
            'provider'       => $activeClient->getProviderName(),
            'endpoint'       => strtoupper($activeClient->getProviderName()) . ($streamingEnabled ? ' (Stream)' : ' (Sync)'),
            'history_loaded' => count($rawHistory) . ' messages',
            'turns'          => [],
            'tool_executions' => [],
        ];

        $fullTextAccumulator = '';
        $finalUsageMetadata = [];
        $finalSafetyRatings = [];

        // Multi-turn loop
        for ($i = 0; $i < self::MAX_TURNS; ++$i) {
            if ($onStatusUpdate && $i > 0) {
                $onStatusUpdate('Réflexion supplémentaire...', 'thinking');
            }

            $debugAccumulator['system_prompt'] = $systemInstruction;
            $debugAccumulator['history'] = $contents;

            // ---- EXECUTION (Streaming ou Synchrone) ----
            $debugOut = [];
            $currentTurnText = '';
            $currentThinking = '';
            $currentFunctionCalls = [];
            $streamDebugParts = [];
            $blockedCategory = null;

            // Choix entre mode streaming ou synchrone
            if ($streamingEnabled) {
                $stream = $activeClient->streamGenerateContent(
                    $systemInstruction,
                    $contents,
                    $toolDefinitions,
                    null,
                    $debugOut,
                );
                $chunks = $stream; // Itérateur de chunks
            } else {
                // Mode synchrone: wrappez la réponse dans un tableau pour utiliser le même code
                $response = $activeClient->generateContent(
                    $systemInstruction,
                    $contents,
                    $toolDefinitions,
                    null,  // model
                    null,  // thinkingConfigOverride
                    $debugOut,
                );
                $chunks = [$response]; // Tableau contenant la réponse unique
            }

            // Consommation des chunks (normalisés, qu'ils viennent du streaming ou du sync)
            foreach ($chunks as $chunk) {
                // Debug storage
                if ($debugMode) {
                    $streamDebugParts[] = $chunk;
                }

                // Usage metadata
                if (!empty($chunk['usage'])) {
                    $finalUsageMetadata = $chunk['usage'];
                }

                // Safety ratings
                if (!empty($chunk['safety_ratings'])) {
                    $finalSafetyRatings = $chunk['safety_ratings'];
                }

                // Blocked response
                if ($chunk['blocked']) {
                    $blockedCategory = $chunk['blocked_category'];
                }

                // Thinking content
                if ($chunk['thinking'] !== null) {
                    if ($onStatusUpdate && empty($currentTurnText)) {
                        $onStatusUpdate('L\'IA réfléchit...', 'thinking_token');
                    }
                    $currentThinking .= $chunk['thinking'];
                }

                // Text content
                if ($chunk['text'] !== null) {
                    $currentTurnText .= $chunk['text'];
                    if ($onToken) {
                        $onToken($chunk['text']);
                    }
                }

                // Function calls
                foreach ($chunk['function_calls'] as $fc) {
                    $currentFunctionCalls[] = $fc;
                }
            }
            // ---- END EXECUTION ----

            // Extract actual request params from LLM client (NOW that Generator has executed)
            if (!empty($debugOut['actual_request_params'])) {
                $debugAccumulator['preset_config'] = $debugOut['actual_request_params'];
                // Add streaming mode info
                $debugAccumulator['preset_config']['streaming_enabled'] = $streamingEnabled;
            }
            if (!empty($debugOut['raw_request_body'])) {
                $debugAccumulator['raw_request_body'] = $debugOut['raw_request_body'];
            }
            // Chunks bruts de l'API (avant normalisation) pour vrai debug
            if (!empty($debugOut['raw_api_chunks'])) {
                $debugAccumulator['raw_api_chunks'] = $debugOut['raw_api_chunks'];
            }
            if (!empty($debugOut['raw_api_response'])) {
                $debugAccumulator['raw_api_response'] = $debugOut['raw_api_response'];
            }

            // Prepend thinking for debug display (so template can extract it)
            if (!empty($currentThinking)) {
                $currentTurnText = '<thinking>' . $currentThinking . '</thinking>' . $currentTurnText;
            }

            // Sanitize accumulated text
            $currentTurnText = TextUtil::sanitizeUtf8($currentTurnText);

            // Strip thinking tags for clean storage / final answer
            $cleanText = preg_replace('/<thinking>.*?<\/thinking>/is', '', $currentTurnText);
            $cleanText = preg_replace('/<\/?thinking[^>]*>/i', '', $cleanText);
            $cleanText = trim($cleanText);

            // If response is empty due to safety block, provide user feedback
            if (empty($cleanText) && $blockedCategory !== null) {
                $categoryLabels = [
                    'HARM_CATEGORY_HARASSMENT'       => 'harcèlement',
                    'HARM_CATEGORY_HATE_SPEECH'      => 'discours haineux',
                    'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'contenu explicite',
                    'HARM_CATEGORY_DANGEROUS_CONTENT' => 'contenu dangereux',
                ];
                $label = $categoryLabels[$blockedCategory] ?? $blockedCategory;
                $cleanText = "⚠️ Ma réponse a été bloquée par les filtres de sécurité (catégorie : {$label}). Veuillez reformuler votre demande.";

                if ($onToken) {
                    $onToken($cleanText);
                }
            }

            $fullTextAccumulator .= $cleanText;

            // Build model parts for history
            $modelParts = [];
            if ('' !== $cleanText) {
                $modelParts[] = ['text' => $cleanText];
            }
            foreach ($currentFunctionCalls as $fc) {
                $safeFc = [
                    'name' => $fc['name'] ?? 'unknown',
                    'args' => (object) ($fc['args'] ?? []),
                ];
                $modelParts[] = ['functionCall' => $safeFc];
            }

            if (!empty($modelParts)) {
                $contents[] = ['role' => 'model', 'parts' => $modelParts];
            }

            // Debug Info Aggregation
            if ($debugMode) {
                $functionNames = array_map(fn ($fc) => $fc['name'] ?? 'unknown', $currentFunctionCalls);

                $debugAccumulator['turns'][] = [
                    'turn'                => $i + 1,
                    'thinking'            => $currentThinking,  // Réflexion accumulée complète
                    'text'                => $cleanText,        // Texte sans thinking
                    'text_content'        => $currentTurnText,  // Format ancien pour compatibilité
                    'has_text'            => !empty($currentTurnText),
                    'function_calls_count' => count($currentFunctionCalls),
                    'function_names'      => $functionNames,
                    'function_calls_data' => $currentFunctionCalls,
                    'function_calls'      => $currentFunctionCalls,
                    'raw_chunks_count'    => count($streamDebugParts),
                ];
                $debugAccumulator['usage']        = $finalUsageMetadata;
                $debugAccumulator['safety']       = $finalSafetyRatings;
                $debugAccumulator['raw_response'] = $streamDebugParts;
            }

            // Process Function Calls
            if (!empty($currentFunctionCalls)) {
                $functionResponseParts = [];

                foreach ($currentFunctionCalls as $index => $fc) {
                    $functionName = $fc['name'];
                    $args = $fc['args'] ?? [];

                    if ($onStatusUpdate) {
                        $onStatusUpdate("Exécution de l'outil: {$functionName}...", 'tool:' . $functionName);
                    }

                    $functionResponse = $this->executeTool($functionName, $args);

                    if (null !== $functionResponse) {
                        $functionResponseParts[] = [
                            'functionResponse' => [
                                'name'     => $functionName,
                                'response' => is_array($functionResponse) ? $functionResponse : ['content' => $functionResponse],
                            ],
                        ];

                        if ($debugMode) {
                            $preview = is_string($functionResponse) ? $functionResponse : json_encode($functionResponse, JSON_UNESCAPED_UNICODE);
                            $debugAccumulator['tool_executions'][] = [
                                'tool'           => $functionName,
                                'params'         => $args,
                                'result_preview' => mb_substr($preview, 0, 100) . '...',
                            ];

                            $lastTurnIndex = count($debugAccumulator['turns']) - 1;
                            if (isset($debugAccumulator['turns'][$lastTurnIndex]['function_calls_data'][$index])) {
                                $debugAccumulator['turns'][$lastTurnIndex]['function_calls_data'][$index]['response'] = $functionResponse;
                            }
                        }
                    }
                }

                if (!empty($functionResponseParts)) {
                    $contents[] = ['role' => 'function', 'parts' => $functionResponseParts];
                    $rawHistory[] = ['role' => 'function', 'parts' => $functionResponseParts];
                    continue; // Loop back for model to process tool results
                }
            }

            // No function calls → end of turn, save history
            if (!$isStateless) {
                if (!empty($message)) {
                    $rawHistory[] = ['role' => 'user', 'parts' => [['text' => TextUtil::sanitizeUtf8($message)]]];
                }
                if (!empty($modelParts)) {
                    $rawHistory[] = [
                        'role'  => 'model',
                        'parts' => $modelParts,
                        'metadata' => [
                            'debug' => $debugMode ? $debugAccumulator : null,
                            'usage' => $finalUsageMetadata,
                        ],
                    ];
                }
            }

            // Save Debug (DB + Cache)
            $debugId = null;
            if ($debugMode) {
                $debugId = uniqid('dbg_', true);

                // Save to DB (primary storage)
                $debugLog = new DebugLog();
                $debugLog->setDebugId($debugId);
                $debugLog->setConversationId($options['conversation_id'] ?? null);
                $debugLog->setCreatedAt(new \DateTimeImmutable());
                $debugLog->setData($debugAccumulator);
                $this->em->persist($debugLog);
                $this->em->flush();

                // Also keep in cache for backward compatibility
                $this->cache->get("synapse_debug_{$debugId}", function (ItemInterface $item) use ($debugAccumulator) {
                    $item->expiresAfter(86400);
                    return $debugAccumulator;
                });
            }

            // Reset l'override après l'appel
            if ($presetOverride !== null) {
                $this->configProvider->setOverride(null);
            }

            return [
                'answer'   => $fullTextAccumulator,
                'debug_id' => $debugId,
                'usage'    => $finalUsageMetadata,
                'safety'   => $finalSafetyRatings,
            ];
        }

        // Max turns exceeded
        // Reset l'override après l'appel
        if ($presetOverride !== null) {
            $this->configProvider->setOverride(null);
        }

        return [
            'answer'   => "Désolé, je n'ai pas pu traiter votre demande après plusieurs tentatives.",
            'debug_id' => null,
            'usage'    => $finalUsageMetadata,
            'safety'   => $finalSafetyRatings,
        ];
    }

    public function resetConversation(): void
    {
    }

    public function getConversationHistory(): array
    {
        return [];
    }

    private function buildToolDefinitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name'        => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters'  => $tool->getInputSchema(),
            ];
        }

        return $definitions;
    }

    private function executeTool(string $name, array $args): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                $result = $tool->execute($args);

                if (is_string($result) || is_array($result) || is_object($result)) {
                    return $result;
                }

                return (string) $result;
            }
        }

        return null;
    }

    private function sanitizeHistoryForNewTurn(array $history): array
    {
        $sanitized = [];

        foreach ($history as $message) {
            $role = $message['role'] ?? '';
            $parts = $message['parts'] ?? [];

            if (empty($parts)) {
                continue;
            }

            $cleanParts = [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $part['text'] = TextUtil::sanitizeUtf8($part['text']);
                    $cleanParts[] = $part;
                } elseif (isset($part['functionCall']) || isset($part['functionResponse'])) {
                    $cleanParts[] = $part;
                }
            }

            if (!empty($cleanParts)) {
                $sanitized[] = [
                    'role'  => $role,
                    'parts' => $cleanParts,
                ];
            }
        }

        return $sanitized;
    }
}
