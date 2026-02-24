<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Chat;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseGenerationCompletedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseGenerationStartedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseToolCallCompletedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseBundle\Shared\Util\TextUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
 *    'usage' => [...], 'safety_ratings' => [...], 'blocked' => bool, 'blocked_reason' => string|null]
 */
class ChatService
{
    /** @var int Nombre de tours maximum pour la boucle de réflexion multi-tours (function calling) */
    private const MAX_TURNS = 5;

    public function __construct(
        private LlmClientRegistry $llmRegistry,
        private PromptBuilder $promptBuilder,
        private ToolRegistry $toolRegistry,
        private ConfigProviderInterface $configProvider,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private ?\ArnaudMoncondhuy\SynapseBundle\Core\Manager\ConversationManager $conversationManager = null,
    ) {}

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
        if (empty($message) && ($options['reset_conversation'] ?? false)) {
            return ['answer' => '', 'debug' => null];
        }

        if ($onStatusUpdate) {
            $onStatusUpdate('Analyse de la demande...', 'thinking');
        }

        // ── DISPATCH GENERATION STARTED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationStartedEvent($message, $options));

        // ── DISPATCH PRE-PROMPT EVENT ──
        // ContextBuilderSubscriber will populate prompt, config, and tool definitions
        $prePromptEvent = $this->dispatcher->dispatch(new SynapsePrePromptEvent($message, $options));
        $prompt = $prePromptEvent->getPrompt();
        $config = $prePromptEvent->getConfig();

        // Support preset override
        $presetOverride = $options['preset'] ?? null;
        if ($presetOverride instanceof SynapsePreset) {
            $config = $this->configProvider->getConfigForPreset($presetOverride);
            $this->configProvider->setOverride($config);
        }

        // Check debug mode
        $globalDebugMode = $config['debug_mode'] ?? false;
        $debugMode = ($options['debug'] ?? false) || ($globalDebugMode && ($options['debug'] !== false));

        // Get LLM client and config
        $activeClient = $this->llmRegistry->getClient();
        $streamingEnabled = $config['streaming_enabled'] ?? true;

        // Accumulators
        $fullTextAccumulator = '';
        $finalUsageMetadata = [];
        $finalSafetyRatings = [];
        $debugId = null;
        $firstTurnRawData = []; // Capture raw API data from first turn

        // Multi-turn loop
        for ($turn = 0; $turn < self::MAX_TURNS; ++$turn) {
            if ($onStatusUpdate && $turn > 0) {
                $onStatusUpdate('Réflexion supplémentaire...', 'thinking');
            }

            $debugOut = [];
            $hasToolCalls = false;

            // ── LLM CALL (Streaming or Sync) ──
            if ($streamingEnabled) {
                $chunks = $activeClient->streamGenerateContent(
                    $prompt['contents'],
                    $prompt['toolDefinitions'] ?? [],
                    null,
                    $debugOut,
                );
            } else {
                $response = $activeClient->generateContent(
                    $prompt['contents'],
                    $prompt['toolDefinitions'] ?? [],
                    null,
                    [],
                    $debugOut,
                );
                $chunks = [$response];
            }

            $modelText = '';
            $modelToolCalls = [];

            // ── PROCESS CHUNKS ──
            foreach ($chunks as $chunk) {
                // Dispatch ChunkReceivedEvent (for debug logging and streaming)
                $this->dispatcher->dispatch(new SynapseChunkReceivedEvent($chunk, $turn));

                // Accumulate text
                if (!empty($chunk['text'])) {
                    $fullTextAccumulator .= $chunk['text'];
                    $modelText .= $chunk['text'];
                    if ($onToken) {
                        $onToken($chunk['text']);
                    }
                }

                // Handle thinking (add to model parts for history)
                if (!empty($chunk['thinking'])) {
                    // Note: thinking is handled by native LLM thinking, not stored separately in history
                }

                // Update usage/safety metadata
                if (!empty($chunk['usage'])) {
                    $finalUsageMetadata = $chunk['usage'];
                }
                if (!empty($chunk['safety_ratings'])) {
                    $finalSafetyRatings = $chunk['safety_ratings'];
                }

                // Handle blocked responses
                if ($chunk['blocked'] ?? false) {
                    $reason = $chunk['blocked_reason'] ?? 'contenu bloqué par les filtres de sécurité';
                    $blockedMsg = "⚠️ Ma réponse a été bloquée ({$reason}). Veuillez reformuler votre demande.";
                    $fullTextAccumulator .= $blockedMsg;
                    $modelText .= $blockedMsg;
                    if ($onToken) {
                        $onToken($blockedMsg);
                    }
                }

                // Collect function calls in OpenAI format
                if (!empty($chunk['function_calls'])) {
                    $hasToolCalls = true;
                    foreach ($chunk['function_calls'] as $fc) {
                        $modelToolCalls[] = [
                            'id'       => $fc['id'],
                            'type'     => 'function',
                            'function' => [
                                'name'      => $fc['name'],
                                'arguments' => json_encode($fc['args'] ?? [], JSON_UNESCAPED_UNICODE),
                            ],
                        ];
                    }
                }
            }

            // ── CAPTURE RAW DATA FROM FIRST TURN ──
            // Must be done AFTER the loop because $debugOut is populated by the generator during/after iteration
            if ($turn === 0 && !empty($debugOut)) {
                $firstTurnRawData = $debugOut;
            }

            // ── ADD MODEL RESPONSE TO HISTORY (OpenAI format) ──
            if ($modelText !== '' || !empty($modelToolCalls)) {
                $entry = ['role' => 'assistant', 'content' => $modelText ?: null];
                if (!empty($modelToolCalls)) {
                    $entry['tool_calls'] = $modelToolCalls;
                }
                $prompt['contents'][] = $entry;
            }

            // ── PROCESS TOOL CALLS ──
            if ($hasToolCalls && !empty($modelToolCalls)) {
                // Dispatch ToolCallRequestedEvent
                $toolEvent = $this->dispatcher->dispatch(new SynapseToolCallRequestedEvent($modelToolCalls));
                $toolResults = $toolEvent->getResults();

                // Add tool responses to prompt for next iteration (one message per tool)
                foreach ($modelToolCalls as $tc) {
                    $toolName = $tc['function']['name'];
                    $toolResult = $toolResults[$toolName] ?? null;

                    if ($onStatusUpdate) {
                        $onStatusUpdate("Exécution de l'outil: {$toolName}...", 'tool:' . $toolName);
                    }

                    if (null !== $toolResult) {
                        $this->dispatcher->dispatch(new SynapseToolCallCompletedEvent($toolName, $toolResult, $tc));
                        $prompt['contents'][] = [
                            'role'         => 'tool',
                            'tool_call_id' => $tc['id'],
                            'content'      => is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                        ];
                    }
                }

                // Continue loop back for model to process results
                continue;
            }

            // No tool calls → end of exchange
            break;
        }

        // ── FINALIZE AND LOG ──
        if ($debugMode) {
            $debugId = uniqid('dbg_', true);

            // Dispatch completion event for debug logging
            // DebugLogSubscriber will handle cache storage and DB persistence
            $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                $debugId,
                $config['model'] ?? 'unknown',
                $activeClient->getProviderName(),
                $finalUsageMetadata,
                $finalSafetyRatings,
                $debugMode,
                $firstTurnRawData
            ));
        }

        // Reset preset override if applicable
        if ($presetOverride !== null) {
            $this->configProvider->setOverride(null);
        }

        // ── DISPATCH GENERATION COMPLETED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationCompletedEvent(
            $fullTextAccumulator,
            $finalUsageMetadata,
            $debugId
        ));

        return [
            'answer'   => $fullTextAccumulator,
            'debug_id' => $debugId,
            'usage'    => $finalUsageMetadata,
            'safety'   => $finalSafetyRatings,
            'model'    => $config['model'] ?? ($config['provider'] ?? 'unknown'),
        ];
    }

    /**
     * Réinitialise l'historique de conversation actuel.
     * Supprime la conversation en base de données si elle existe.
     */
    public function resetConversation(): void
    {
        if ($this->conversationManager) {
            $conversation = $this->conversationManager->getCurrentConversation();
            if ($conversation) {
                $this->conversationManager->deleteConversation($conversation);
                $this->conversationManager->setCurrentConversation(null);
            }
        }
    }

    /**
     * Récupère l'historique de conversation complet au format OpenAI.
     *
     * @return array<int, array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> Messages au format OpenAI
     */
    public function getConversationHistory(): array
    {
        if (!$this->conversationManager) {
            return [];
        }

        $conversation = $this->conversationManager->getCurrentConversation();
        if (!$conversation) {
            return [];
        }

        return $this->conversationManager->getHistoryArray($conversation);
    }

    /**
     * Convertit un MessageRole vers le format OpenAI canonical
     */
    private function convertRoleToOpenAi(\ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole $role): string
    {
        return match ($role) {
            \ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole::USER => 'user',
            \ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole::MODEL => 'assistant',
            \ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole::FUNCTION => 'tool',
            \ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole::SYSTEM => 'user',
        };
    }
}
