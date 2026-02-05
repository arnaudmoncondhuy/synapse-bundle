<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\Infra\GeminiClient;
use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Orchestrateur principal des échanges conversationnels avec l'IA.
 *
 * Cette classe coordonne :
 * 1. La construction du contexte (Prompt Builder).
 * 2. La communication avec l'API externe (GeminiClient) en mode Streaming.
 * 3. L'exécution dynamique des outils (Function Calling).
 * 4. La boucle de réflexion multi-tours avec l'IA.
 */
class ChatService
{
    private const MAX_TURNS = 5;

    public function __construct(
        private GeminiClient $geminiClient,
        private PromptBuilder $promptBuilder,
        private iterable $tools,
        private CacheInterface $cache,
        private ConfigProviderInterface $configProvider,
    ) {
    }

    /**
     * Traite un message utilisateur et retourne la réponse de l'IA (après exécution d'outils si nécessaire).
     *
     * @param string $message
     * @param array $options
     * @param callable|null $onStatusUpdate Function(string $message, string $step): void
     * @param callable|null $onToken        Function(string $token): void (Nouveau pour le streaming)
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
        $effectiveModel = $config['model'] ?? 'gemini-2.5-flash';

        $debugAccumulator = [
            'model' => $effectiveModel,
            'endpoint' => 'Vertex AI (Stream)',
            'history_loaded' => count($rawHistory).' messages',
            'turns' => [],
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

            // ---- STREAMING EXECUTION ----
            $stream = $this->geminiClient->streamGenerateContent(
                $systemInstruction,
                $contents,
                $toolDefinitions,
                null
            );

            $currentTurnText = '';
            $currentFunctionCalls = [];
            $streamDebugParts = [];

            // Consommation du flux
            foreach ($stream as $chunk) {
                // Debug storage
                if ($options['debug'] ?? false) {
                    $streamDebugParts[] = $chunk;
                }

                // 1. Métadonnées (souvent à la fin ou mises à jour à chaque chunk)
                if (isset($chunk['usageMetadata'])) {
                    $finalUsageMetadata = $chunk['usageMetadata'];
                }
                
                $candidate = $chunk['candidates'][0] ?? [];
                
                if (isset($candidate['safetyRatings'])) {
                    $finalSafetyRatings = $candidate['safetyRatings'];
                }

                $content = $candidate['content'] ?? [];
                $parts = $content['parts'] ?? [];

                // 2. Traitement des parts (Texte ou Fonctions)
                foreach ($parts as $part) {
                    // Handle native thoughts (keep stream alive)
                    if (isset($part['thought']) && true === $part['thought']) {
                         if ($onStatusUpdate && empty($currentTurnText)) {
                             // Only update status if we haven't started speaking yet
                             $onStatusUpdate('L\'IA réfléchit...', 'thinking_token');
                         }
                         continue;
                    }

                    // Text
                    if (isset($part['text'])) {
                        $textChunk = $part['text'];
                        
                        // Accumulation locale pour ce tour
                        $currentTurnText .= $textChunk;
                        
                        // Streaming vers le frontend via callback
                        // On n'envoie PAS si on est en train d'accumuler une fonction (rare qu'ils soient mélangés mais prudence)
                        // Et on évite d'envoyer les tags <thinking> si possible (filtrage post-hoc difficile en stream)
                        // Pour l'instant, on envoie tout le texte brut, le frontend affichera.
                        if ($onToken) {
                            $onToken($textChunk);
                        }
                    }

                    // Function Calls (souvent un chunk distinct ou à la fin)
                    if (isset($part['functionCall'])) {
                        // On stocke l'appel.
                        // Attention : en streaming, un functionCall peut-il être splitté ?
                        // Vertex renvoie généralement l'objet functionCall complet dans un chunk.
                        // Si ce n'est pas le cas, mon parser JSON dans GeminiClient aura reconstitué l'objet complet.
                        $currentFunctionCalls[] = $part['functionCall'];
                    }
                }
            }
            // ---- END STREAM ----

            // Nettoyage du texte accumulé (comme avant)
            $currentTurnText = TextUtil::sanitizeUtf8($currentTurnText);
            
            // Suppression des tags thinking pour l'historique et le texte final global
            // Note: Le frontend a reçu les tags via stream, c'est au JS de clean si besoin visuellement.
            // Ici on clean pour la mémoire propres.
            $cleanText = preg_replace('/<thinking>.*?<\/thinking>/is', '', $currentTurnText);
            $cleanText = preg_replace('/<\/?thinking[^>]*>/i', '', $cleanText);
            $cleanText = trim($cleanText);

            $fullTextAccumulator .= $cleanText;

            // Ajout réponse Model à l'historique (Note: On garde le texte original pour l'intégrité du tour)
            // Mais pour éviter de polluer le contexte avec des thoughts, on peut utiliser le cleanText.
            // Cependant, Google recommande de garder les thoughts. 
            // Vu qu'on filtrait avant, on continue de filtrer pour l'historique. 
            // Ainsi, la BDD reste propre.
            $modelParts = [];
            if ('' !== $cleanText) {
                $modelParts[] = ['text' => $cleanText];
            }
            foreach ($currentFunctionCalls as $fc) {
                // IMPORTANT: Normalisation pour éviter l'erreur "cannot start list" 
                // et filtrage des champs non-standards de Gemini 2.x
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
            if ($options['debug'] ?? false) {
                // Calculate derived metrics for template compatibility
                $functionNames = array_map(fn ($fc) => $fc['name'] ?? 'unknown', $currentFunctionCalls);
                
                $debugAccumulator['turns'][] = [
                    'turn' => $i + 1,
                    'text_content' => $currentTurnText,
                    'has_text' => !empty($currentTurnText),
                    'function_calls_count' => count($currentFunctionCalls),
                    'function_names' => $functionNames,
                    'function_calls_data' => $currentFunctionCalls, // Alias for template compatibility
                    'function_calls' => $currentFunctionCalls,
                    'raw_chunks_count' => count($streamDebugParts),
                ];
                $debugAccumulator['usage'] = $finalUsageMetadata;
                $debugAccumulator['safety'] = $finalSafetyRatings;
                $debugAccumulator['raw_response'] = $streamDebugParts;
            }

            // Process Function Calls
            if (!empty($currentFunctionCalls)) {
                $functionResponseParts = [];

                foreach ($currentFunctionCalls as $index => $fc) {
                    $functionName = $fc['name'];
                    $args = $fc['args'] ?? [];

                    if ($onStatusUpdate) {
                        $onStatusUpdate("Exécution de l'outil: {$functionName}...", 'tool:'.$functionName);
                    }

                    $functionResponse = $this->executeTool($functionName, $args);

                    if (null !== $functionResponse) {
                        $functionResponseParts[] = [
                            'functionResponse' => [
                                'name' => $functionName,
                                'response' => is_array($functionResponse) ? $functionResponse : ['content' => $functionResponse],
                            ],
                        ];

                        if ($options['debug'] ?? false) {
                            $debugAccumulator['tool_executions'][] = [
                                'tool' => $functionName,
                                'params' => $args,
                                'result_preview' => substr((string) $functionResponse, 0, 100).'...',
                            ];

                            // Attach full response to the specific turn data for easier display in debug view
                            // We target the current turn (last added) and the specific function call index
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
                    // On boucle pour laisser le modèle répondre après l'outil
                    continue; 
                }
            }

            // Pas d'appel de fonction -> Fin du tour
            // Sauvegarde historique final
            if (!$isStateless) {
                if (!empty($message)) {
                     $rawHistory[] = ['role' => 'user', 'parts' => [['text' => TextUtil::sanitizeUtf8($message)]]];
                }
                if (!empty($modelParts)) {
                     // On attache les métadonnées finales au dernier message du modèle
                     $rawHistory[] = [
                        'role' => 'model',
                        'parts' => $modelParts,
                        'metadata' => [
                            'debug' => ($options['debug'] ?? false) ? $debugAccumulator : null,
                            'usage' => $finalUsageMetadata, // IMPORTANT pour la BDD
                        ]
                    ];
                }
            }

            // Save Debug Cache
            $debugId = null;
            if ($options['debug'] ?? false) {
                $debugId = uniqid('dbg_', true);
                $this->cache->get("synapse_debug_{$debugId}", function (ItemInterface $item) use ($debugAccumulator) {
                    $item->expiresAfter(86400);
                    return $debugAccumulator;
                });
            }

            return [
                'answer' => $fullTextAccumulator,
                'debug_id' => $debugId,
                'usage' => $finalUsageMetadata,
                'safety' => $finalSafetyRatings,
            ];
        }

        // Max turns exceeded
        return [
            'answer' => "Désolé, je n'ai pas pu traiter votre demande après plusieurs tentatives.",
            'debug_id' => null,
            'usage' => $finalUsageMetadata,
            'safety' => $finalSafetyRatings,
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

        $config = $this->configProvider->getConfig();
        $riskEnabled = $config['risk_detection']['enabled'] ?? false;

        foreach ($this->tools as $tool) {
            // Filter ReportRiskTool if disabled
            if ($tool->getName() === 'report_risk' && !$riskEnabled) {
                continue;
            }

            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getInputSchema(),
            ];
        }

        return $definitions;
    }

    private function executeTool(string $name, array $args): ?string
    {
        $config = $this->configProvider->getConfig();
        $riskEnabled = $config['risk_detection']['enabled'] ?? false;

        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                // Prevent execution if risk detection is disabled and tool is report_risk
                if ($name === 'report_risk' && !$riskEnabled) {
                    return null;
                }

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
                    'role' => $role,
                    'parts' => $cleanParts,
                ];
            }
        }

        return $sanitized;
    }
}
