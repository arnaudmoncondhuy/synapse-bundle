<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\Infra\GeminiClient;
use ArnaudMoncondhuy\SynapseBundle\Util\TextUtil;

/**
 * Main orchestrator for the AI chat.
 *
 * Coordinates:
 * - Context Provider (system prompt)
 * - Conversation Handler (history)
 * - Gemini Client (API)
 * - Tools (function calling)
 */
class ChatService
{
    private const MAX_TURNS = 5;

    /**
     * @param iterable<AiToolInterface> $tools
     */
    public function __construct(
        private GeminiClient $geminiClient,
        private PromptBuilder $promptBuilder,
        private ConversationHandlerInterface $conversationHandler,
        private iterable $tools,
    ) {
    }

    /**
     * Processes a user message and returns the AI response.
     *
     * @param string $message The user's message.
     * @param array $options Options: 'reset_conversation', 'debug', 'stateless'.
     * @param callable|null $onStatusUpdate Callback for streaming updates: function(string $message, string $step): void
     * @return array{answer: string, debug: ?array}
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
        $toolDefinitions = $this->buildToolDefinitions();

        // Load history
        if ($isStateless) {
            $contents = [
                ['role' => 'user', 'parts' => [['text' => $message]]]
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

        $debugAccumulator = [
            'history_loaded' => count($rawHistory) . ' messages',
            'turns' => [],
            'tool_executions' => [],
        ];

        $fullTextAccumulator = '';

        // Multi-turn loop (handle function calls)
        for ($i = 0; $i < self::MAX_TURNS; $i++) {
            if ($onStatusUpdate && $i > 0) {
                $onStatusUpdate('Réflexion supplémentaire...', 'thinking');
            }

            $response = $this->geminiClient->generateContent(
                $systemInstruction,
                $contents,
                $toolDefinitions
            );

            $parts = $response['parts'] ?? [];
            $currentTurnText = '';
            $functionCalls = [];

            // Extract text and function calls
            foreach ($parts as $part) {
                // Handle native Gemini 2.0+ thinking field
                if (isset($part['thought']) && $part['thought'] === true) {
                    // Note: 'thought' flag might be on a text part, or separate.
                    // If the API evolution separates them, we might need adjustments.
                    // Currently assuming text content IS the thought if thought=true.
                    if (isset($part['text'])) {
                        $currentTurnText .= "<thinking>" . $part['text'] . "</thinking>\n";
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
            if ($currentTurnText !== '') {
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
                    'has_text' => $currentTurnText !== '',
                    'function_calls_count' => count($functionCalls),
                    'function_names' => array_map(fn($fc) => $fc['name'], $functionCalls),
                ];
            }

            // Process function calls
            if (!empty($functionCalls)) {
                $functionResponseParts = [];

                foreach ($functionCalls as $fc) {
                    $functionName = $fc['name'];
                    $args = $fc['args'] ?? [];

                    if ($onStatusUpdate) {
                        $onStatusUpdate("Exécution de l'outil: {$functionName}...", 'tool:' . $functionName);
                    }

                    $functionResponse = $this->executeTool($functionName, $args);

                    if ($functionResponse !== null) {
                        $functionResponseParts[] = [
                            'functionResponse' => [
                                'name' => $functionName,
                                'response' => ['content' => $functionResponse]
                            ]
                        ];

                        if ($options['debug'] ?? false) {
                            $debugAccumulator['tool_executions'][] = [
                                'tool' => $functionName,
                                'params' => $args,
                                'result_preview' => substr((string) $functionResponse, 0, 100) . '...',
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
                        ]
                    ];
                }

                $this->conversationHandler->saveHistory($rawHistory);
            }

            $debugAccumulator['total_turns'] = $i + 1;

            return [
                'answer' => $fullTextAccumulator,
                'debug' => ($options['debug'] ?? false) ? $debugAccumulator : null,
            ];
        }

        // Max turns exceeded
        return [
            'answer' => "Désolé, je n'ai pas pu traiter votre demande après plusieurs tentatives.",
            'debug' => ($options['debug'] ?? false) ? $debugAccumulator : null,
        ];
    }

    /**
     * Clears the conversation history.
     */
    public function resetConversation(): void
    {
        $this->conversationHandler->clearHistory();
    }

    /**
     * Returns the current conversation history.
     */
    public function getConversationHistory(): array
    {
        return $this->conversationHandler->loadHistory();
    }

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

    private function executeTool(string $name, array $args): ?string
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                $result = $tool->execute($args);

                if (is_string($result)) {
                    return $result;
                }

                return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
