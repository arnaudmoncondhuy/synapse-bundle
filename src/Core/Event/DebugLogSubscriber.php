<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Event;

use ArnaudMoncondhuy\SynapseBundle\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapsePrePromptEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use DateTimeImmutable;

/**
 * Accumulates debug data throughout the LLM exchange and persists it.
 *
 * Listens to:
 * - SynapsePrePromptEvent: captures system prompt and initial config
 * - SynapseChunkReceivedEvent: accumulates chunk data (tokens, thinking, tool calls)
 * - SynapseExchangeCompletedEvent: persists final debug data via logger
 */
class DebugLogSubscriber implements EventSubscriberInterface
{
    private array $debugAccumulator = [];

    public function __construct(
        private SynapseDebugLoggerInterface $debugLogger,
        private CacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class         => ['onPrePrompt', 50],
            SynapseChunkReceivedEvent::class     => ['onChunkReceived', 0],
            SynapseExchangeCompletedEvent::class => ['onExchangeCompleted', -100], // Low priority, let others finish first
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        // Capture initial context for debug
        $prompt = $event->getPrompt();
        $config = $event->getConfig();
        $systemInstruction = $prompt['systemInstruction'] ?? null;
        // Handle both string and array formats for backward compatibility
        if (is_array($systemInstruction)) {
            $systemInstruction = $systemInstruction['parts'][0]['text'] ?? null;
        }

        // Extract preset config parameters for display
        $presetConfig = [
            'model'              => $config['model'] ?? null,
            'provider'           => $config['provider'] ?? null,
            'temperature'        => $config['generation_config']['temperature'] ?? null,
            'top_p'              => $config['generation_config']['top_p'] ?? null,
            'top_k'              => $config['generation_config']['top_k'] ?? null,
            'max_output_tokens'  => $config['generation_config']['max_output_tokens'] ?? null,
            'thinking_enabled'   => $config['thinking']['enabled'] ?? false,
            'thinking_budget'    => $config['thinking']['budget'] ?? null,
            'safety_enabled'     => $config['safety_settings']['enabled'] ?? false,
            'tools_sent'         => !empty($prompt['toolDefinitions']),
            'streaming_enabled'  => $config['streaming_enabled'] ?? false,
            'context_caching'    => $config['context_caching'] ?? false,
        ];

        $this->debugAccumulator = [
            'system_prompt'       => $systemInstruction,
            'config'              => $config,
            'preset_config'       => $presetConfig,
            'history'             => $prompt['contents'] ?? [],
            'history_size'        => count($prompt['contents'] ?? []),
            'turns'               => [],
            'tool_executions'     => [],
            'raw_request_body'    => null,
            'raw_response'        => [],
            'raw_api_chunks'      => [],
            'raw_api_response'    => null,
        ];
    }

    public function onChunkReceived(SynapseChunkReceivedEvent $event): void
    {
        $chunk = $event->getChunk();
        $rawChunk = $event->getRawChunk();
        $turn = $event->getTurn();

        // Accumulate raw API chunks for debug
        if ($rawChunk !== null) {
            $this->debugAccumulator['raw_api_chunks'][] = $rawChunk;
        }

        // Accumulate normalized response chunks
        $this->debugAccumulator['raw_response'][] = $chunk;

        // Initialize turn if not exists
        if (!isset($this->debugAccumulator['turns'][$turn])) {
            $this->debugAccumulator['turns'][$turn] = [
                'turn'           => $turn,
                'text'           => '',
                'thinking'       => '',
                'function_calls' => [],
                'usage'          => [],
                'safety_ratings' => [],
            ];
        }

        // Accumulate text and thinking
        if (!empty($chunk['text'])) {
            $this->debugAccumulator['turns'][$turn]['text'] .= $chunk['text'];
        }
        if (!empty($chunk['thinking'])) {
            $this->debugAccumulator['turns'][$turn]['thinking'] .= $chunk['thinking'];
        }

        // Accumulate function calls
        $functionCalls = $chunk['function_calls'] ?? [];
        if (!empty($functionCalls)) {
            $this->debugAccumulator['turns'][$turn]['function_calls'] = array_merge(
                $this->debugAccumulator['turns'][$turn]['function_calls'],
                $functionCalls
            );
        }

        // Merge usage stats
        if (!empty($chunk['usage'])) {
            $this->debugAccumulator['turns'][$turn]['usage'] = array_merge(
                $this->debugAccumulator['turns'][$turn]['usage'] ?? [],
                $chunk['usage']
            );
        }

        // Merge safety ratings
        if (!empty($chunk['safety_ratings'])) {
            $this->debugAccumulator['turns'][$turn]['safety_ratings'] = array_merge(
                $this->debugAccumulator['turns'][$turn]['safety_ratings'] ?? [],
                $chunk['safety_ratings']
            );
        }
    }

    public function onExchangeCompleted(SynapseExchangeCompletedEvent $event): void
    {
        // Only log if debug mode is enabled
        if (!$event->isDebugMode()) {
            $this->debugAccumulator = [];
            return;
        }

        // Merge raw API data captured from the LLM client
        $rawData = $event->getRawData();
        if (!empty($rawData)) {
            if (!empty($rawData['raw_request_body'])) {
                $this->debugAccumulator['raw_request_body'] = $rawData['raw_request_body'];
            }
            if (!empty($rawData['raw_api_chunks'])) {
                $this->debugAccumulator['raw_api_chunks'] = $rawData['raw_api_chunks'];
            }
            if (!empty($rawData['raw_api_response'])) {
                $this->debugAccumulator['raw_api_response'] = $rawData['raw_api_response'];
            }
        }

        // Complete the debug accumulator with final metadata
        $this->debugAccumulator['model']    = $event->getModel();
        $this->debugAccumulator['provider'] = $event->getProvider();
        $this->debugAccumulator['usage']    = $event->getUsage();
        $this->debugAccumulator['safety']   = $event->getSafety();
        $this->debugAccumulator['created_at'] = new DateTimeImmutable();

        // Prepare lightweight metadata for DB storage
        $metadata = [
            'model'              => $event->getModel(),
            'provider'           => $event->getProvider(),
            'token_usage'        => $event->getUsage(),
            'safety_ratings'     => $event->getSafety(),
            'thinking_enabled'   => $this->debugAccumulator['config']['thinking_enabled'] ?? false,
        ];

        // Pass COMPLETE debug data (not just metadata) for template rendering
        $this->debugLogger->logExchange($event->getDebugId(), $metadata, $this->debugAccumulator);

        // Store complete debug data in cache for quick retrieval (1 day TTL)
        $debugId = $event->getDebugId();
        error_log("DebugLogSubscriber: Storing cache for {$debugId} with " . count($this->debugAccumulator['turns'] ?? []) . " turns");
        $this->cache->get("synapse_debug_{$debugId}", function (ItemInterface $item) use ($debugId) {
            $item->expiresAfter(86400); // 24 hours
            error_log("DebugLogSubscriber: Cache callback executed for {$debugId}");
            return $this->debugAccumulator;
        });

        // Clean up
        $this->debugAccumulator = [];
    }
}
