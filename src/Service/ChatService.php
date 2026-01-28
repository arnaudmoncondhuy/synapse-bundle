<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\Infra\GeminiClient;
use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Orchestrateur principal des échanges conversationnels avec l'IA.
 *
 * Cette classe est le point central du bundle. Elle coordonne :
 * 1. La construction du contexte (Prompt Builder).
 * 2. La communication avec l'API externe (GeminiClient).
 * 3. La persistance de l'historique (ConversationHandler).
 * 4. L'exécution dynamique des outils (Function Calling).
 *
 * Elle gère également la boucle de réflexion multi-tours nécessaire lorsque l'IA
 * décide d'utiliser un ou plusieurs outils avant de formuler une réponse.
 */
class ChatService
{
    private const MAX_TURNS = 5;

    /**
     * @param GeminiClient                 $geminiClient        client HTTP bas niveau pour l'API Gemini (Vertex AI)
     * @param PromptBuilder                $promptBuilder       service de construction du Prompt Système
     * @param ConversationHandlerInterface $conversationHandler gestionnaire de persistance des messages
     * @param iterable<AiToolInterface>    $tools               collection des outils disponibles pour l'IA (injectés via DI)
     * @param CacheInterface               $cache               cache Symfony pour stocker temporairement les données de débogage
     * @param string                       $configuredModel     modèle Gemini configuré
     */
    public function __construct(
        private GeminiClient $geminiClient,
        private PromptBuilder $promptBuilder,
        private ConversationHandlerInterface $conversationHandler,
        private iterable $tools,
        private CacheInterface $cache,
        private string $configuredModel = 'gemini-2.5-flash',
    ) {
    }

    /**
     * Traite un message utilisateur et retourne la réponse de l'IA (après exécution d'outils si nécessaire).
     *
     * Cette méthode gère le cycle de vie complet d'un tour de parole :
     * 1. Chargement de l'historique.
     * 2. Injection du contexte (System Prompt + Persona).
     * 3. Boucle d'interaction (Requête -> IA -> Outil -> Requête -> IA...).
     * 4. Sauvegarde du nouvel historique.
     *
     * @param string $message le message texte envoyé par l'utilisateur
     * @param array{
     *    stateless?: bool,
     *    reset_conversation?: bool,
     *    debug?: bool,
     *    persona?: string,
     *    tools?: array
     * } $options Options de configuration de la requête :
     *  - 'stateless' (bool) : Ne pas charger ni sauvegarder l'historique (mode "one-shot").
     *  - 'reset_conversation' (bool) : Effacer l'historique AVANT de traiter ce message.
     *  - 'debug' (bool) : Activer la collecte d'informations détaillées pour le débogage.
     *  - 'persona' (string) : Clé de la personnalité à utiliser (écrase le défaut).
     *  - 'tools' (array) : Définitions d'outils spécifiques pour cette requête (Optionnel, écrase les défauts).
     * @param callable|null $onStatusUpdate Callback optionnel pour le streaming d'état (feedback UI).
     *                                      Signature: function(string $message, string $step): void
     *
     * @return array{
     *    answer: string,
     *    debug_id: ?string
     * } Tableau contenant :
     *  - 'answer' (string) : La réponse finale en Markdown.
     *  - 'debug_id' (string|null) : ID unique pour récupérer les logs de debug (si options['debug'] = true).
     */
    public function ask(string $message, array $options = [], ?callable $onStatusUpdate = null): array
    {
        $isStateless = $options['stateless'] ?? false;

        // Reset if requested
        if (!$isStateless && ($options['reset_conversation'] ?? false)) {
            $this->conversationHandler->clearHistory();
        }

        // Empty message with reset = just clear
        if (empty($message) && ($options['reset_conversation'] ?? false)) {
            return ['answer' => '', 'debug' => null];
        }

        // Build context
        $personaKey = $options['persona'] ?? null;
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($personaKey);

        // Tools Handling: Dynamic override or default injection
        if (isset($options['tools']) && is_array($options['tools'])) {
            // If manual tools are provided in options (raw array format usually)
            // We use them directly.
            $toolDefinitions = $options['tools'];
        // Note: If using tools from options, execution logic might fail if they are not in $this->tools registry.
        // This assumes the Caller handles tool execution or only passes definintions for model awareness.
        // For now, let's assume standard behavior: we use injected tools unless overridden.
        } else {
            $toolDefinitions = $this->buildToolDefinitions();
        }

        // Load history
        if ($isStateless) {
            $contents = [
                ['role' => 'user', 'parts' => [['text' => $message]]],
            ];
            $rawHistory = [];
        } else {
            $rawHistory = $this->conversationHandler->loadHistory();
            $contents = $this->sanitizeHistoryForNewTurn($rawHistory);
            if (!empty($message)) {
                $contents[] = ['role' => 'user', 'parts' => [['text' => TextUtil::sanitizeUtf8($message)]]];
            }
        }

        if ($onStatusUpdate) {
            $onStatusUpdate('Analyse de la demande...', 'thinking');
        }

        // Model always comes from configuration (no override allowed)
        $effectiveModel = $this->configuredModel;

        $debugAccumulator = [
            'model' => $effectiveModel,
            'endpoint' => 'Vertex AI',
            'history_loaded' => count($rawHistory).' messages',
            'turns' => [],
            'tool_executions' => [],
        ];

        $fullTextAccumulator = '';

        // Multi-turn loop (handle function calls)
        for ($i = 0; $i < self::MAX_TURNS; ++$i) {
            if ($onStatusUpdate && $i > 0) {
                $onStatusUpdate('Réflexion supplémentaire...', 'thinking');
            }

            $debugAccumulator['system_prompt'] = $systemInstruction;
            $debugAccumulator['history'] = $contents;

            $response = $this->geminiClient->generateContent(
                $systemInstruction,
                $contents,
                $toolDefinitions,
                null // Model comes from GeminiClient configuration
            );

            if ($options['debug'] ?? false) {
                $debugAccumulator['raw_response'] = $response;
            }

            $parts = $response['parts'] ?? [];
            $currentTurnText = '';
            $functionCalls = [];

            // Extract text and function calls
            foreach ($parts as $part) {
                // Handle native Gemini 2.0+ thinking field
                if (isset($part['thought']) && true === $part['thought']) {
                    // Note: 'thought' flag might be on a text part, or separate.
                    // If the API evolution separates them, we might need adjustments.
                    // Currently assuming text content IS the thought if thought=true.
                    if (isset($part['text'])) {
                        $currentTurnText .= '<thinking>'.$part['text']."</thinking>\n";
                    }
                    continue; // Skip standard text append
                }

                if (isset($part['text'])) {
                    $currentTurnText .= $part['text'];
                }
                if (isset($part['functionCall'])) {
                    $functionCalls[] = $part['functionCall'];
                }
            }

            $currentTurnText = TextUtil::sanitizeUtf8($currentTurnText);
            $fullTextAccumulator .= $currentTurnText;

            // Add model response to history
            $modelParts = [];
            if ('' !== $currentTurnText) {
                $modelParts[] = ['text' => $currentTurnText];
            }
            foreach ($functionCalls as $fc) {
                $modelParts[] = ['functionCall' => $fc];
            }

            if (!empty($modelParts)) {
                $contents[] = ['role' => 'model', 'parts' => $modelParts];
            }

            // Debug info
            if ($options['debug'] ?? false) {
                $debugAccumulator['turns'][] = [
                    'turn' => $i + 1,
                    'text_content' => $currentTurnText,
                    'text_length' => strlen($currentTurnText),
                    'has_text' => '' !== $currentTurnText,
                    'function_calls_count' => count($functionCalls),
                    'function_names' => array_map(fn ($fc) => $fc['name'], $functionCalls),
                ];
            }

            // Process function calls
            if (!empty($functionCalls)) {
                $functionResponseParts = [];

                foreach ($functionCalls as $fc) {
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
                                'response' => ['content' => $functionResponse],
                            ],
                        ];

                        if ($options['debug'] ?? false) {
                            $debugAccumulator['tool_executions'][] = [
                                'tool' => $functionName,
                                'params' => $args,
                                'result_preview' => substr((string) $functionResponse, 0, 100).'...',
                            ];
                        }
                    }
                }

                if (!empty($functionResponseParts)) {
                    // Update sanitized contents (for Gemini)
                    $contents[] = ['role' => 'function', 'parts' => $functionResponseParts];
                    // Also update raw history (for Session)
                    $rawHistory[] = ['role' => 'function', 'parts' => $functionResponseParts];
                }

                // Continue loop to let model react to tool results
                continue;
            }

            // No function calls = final response
            if (!$isStateless) {
                // 1. Add User Message to Raw History
                if (!empty($message)) {
                    // Note: sanitizing again for safety, though technically done at start
                    $rawHistory[] = ['role' => 'user', 'parts' => [['text' => TextUtil::sanitizeUtf8($message)]]];
                }

                // 2. Add Model Response to Raw History with Metadata
                if (!empty($modelParts)) {
                    $debugAccumulator['total_turns'] = $i + 1;

                    $rawHistory[] = [
                        'role' => 'model',
                        'parts' => $modelParts,
                        'metadata' => [
                            'debug' => ($options['debug'] ?? false) ? $debugAccumulator : null,
                        ],
                    ];
                }

                $this->conversationHandler->saveHistory($rawHistory);
            }

            $debugAccumulator['total_turns'] = $i + 1;

            $debugId = null;
            if ($options['debug'] ?? false) {
                // Generate a unique ID for this debug session
                $debugId = uniqid('dbg_', true);

                // Save to cache (TTL 1 hour)
                $this->cache->get("synapse_debug_{$debugId}", function (ItemInterface $item) use ($debugAccumulator) {
                    $item->expiresAfter(86400);

                    return $debugAccumulator;
                });
            }

            return [
                'answer' => $fullTextAccumulator,
                'debug_id' => $debugId,
                // 'debug' => ... removed, replaced by ID
            ];
        }

        // Max turns exceeded
        $debugId = null;
        if ($options['debug'] ?? false) {
            $debugId = uniqid('dbg_err_', true);
            $this->cache->get("synapse_debug_{$debugId}", function (ItemInterface $item) use ($debugAccumulator) {
                $item->expiresAfter(86400);

                return $debugAccumulator;
            });
        }

        return [
            'answer' => "Désolé, je n'ai pas pu traiter votre demande après plusieurs tentatives.",
            'debug_id' => $debugId,
        ];
    }

    /**
     * Efface complètement l'historique de la conversation en cours.
     *
     * Permet de réinitialiser le contexte pour un nouveau sujet.
     */
    public function resetConversation(): void
    {
        $this->conversationHandler->clearHistory();
    }

    /**
     * Retourne l'historique brut de la conversation courante.
     *
     * @return array<int, array>
     */
    public function getConversationHistory(): array
    {
        return $this->conversationHandler->loadHistory();
    }

    /**
     * Convertit la collection d'objets Tool en définitions conformes à l'API Gemini.
     *
     * @return array<int, array> tableau de définitions JSON Schema
     */
    private function buildToolDefinitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getInputSchema(),
            ];
        }

        return $definitions;
    }

    /**
     * Exécute un outil spécifique par son nom.
     *
     * @param string $name le nom technique de l'outil
     * @param array  $args les arguments fournis par l'IA
     *
     * @return string|null la réponse de l'outil (JSON ou string) ou null si introuvable
     */
    private function executeTool(string $name, array $args): ?string
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                $result = $tool->execute($args);

                if (is_string($result)) {
                    return $result;
                }

                // Force JSON for complex objects
                return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }

        return null;
    }

    /**
     * Prépare l'historique brut pour l'envoi à l'API Gemini.
     *
     * Nettoie les données inutiles et s'assure que le format est strictement respecté.
     *
     * @param array $history L'historique brut (provenant du Handler)
     *
     * @return array L'historique assaini pour l'API
     */
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
