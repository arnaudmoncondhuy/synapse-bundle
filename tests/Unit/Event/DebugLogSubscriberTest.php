<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseBundle\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\DebugLogSubscriber;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapsePrePromptEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tests for DebugLogSubscriber.
 * 
 * Verifies that:
 * - Tool definitions are captured from the prompt
 * - Tool usage (tool_calls + results) is extracted from history
 */
class DebugLogSubscriberTest extends TestCase
{
    private DebugLogSubscriber $subscriber;
    private CacheInterface $cache;
    private SynapseDebugLoggerInterface $debugLogger;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->debugLogger = $this->createMock(SynapseDebugLoggerInterface::class);
        $this->subscriber = new DebugLogSubscriber($this->debugLogger, $this->cache);
    }

    public function testToolDefinitionsAreCaptured(): void
    {
        // Arrange
        $toolDefinitions = [
            [
                'name' => 'search',
                'description' => 'Search the web',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query',
                        ],
                    ],
                ],
            ],
        ];

        $promptContent = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $config = [
            'model' => 'gpt-4',
            'provider' => 'openai',
            'generation_config' => [
                'temperature' => 0.7,
                'top_p' => 1.0,
                'top_k' => null,
                'max_output_tokens' => 2048,
            ],
            'thinking' => ['enabled' => false],
            'safety_settings' => ['enabled' => true],
            'streaming_enabled' => false,
        ];

        $event = new SynapsePrePromptEvent(
            'Hello',  // message
            [],       // options
            ['contents' => $promptContent, 'toolDefinitions' => $toolDefinitions],  // prompt
            $config   // config
        );

        // Act
        $this->subscriber->onPrePrompt($event);

        // Assert - We can't directly access the accumulator, but we can verify the cache stores it
        $this->debugLogger->expects($this->once())
            ->method('logExchange')
            ->with(
                'test-id',
                $this->anything(),
                $this->callback(function (array $debugData) {
                    return isset($debugData['tool_definitions']) 
                        && count($debugData['tool_definitions']) === 1
                        && $debugData['tool_definitions'][0]['name'] === 'search';
                })
            );

        // Trigger the completed event to persist debug data
        $completedEvent = new SynapseExchangeCompletedEvent(
            'test-id',          // debugId
            'gpt-4',            // model
            'openai',           // provider
            [],                 // usage
            [],                 // safety
            true                // debugMode
        );

        $this->subscriber->onExchangeCompleted($completedEvent);
    }

    public function testToolUsageIsExtractedFromHistory(): void
    {
        // Arrange
        $promptContent = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Search for PHP'],
            [
                'role' => 'assistant',
                'content' => 'I will search for you',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'search',
                            'arguments' => '{"query": "PHP"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_1',
                'content' => 'Results: PHP is...',
            ],
        ];

        $config = [
            'model' => 'gpt-4',
            'provider' => 'openai',
            'generation_config' => ['temperature' => 0.7, 'top_p' => 1.0, 'top_k' => null, 'max_output_tokens' => 2048],
            'thinking' => ['enabled' => false],
            'safety_settings' => ['enabled' => true],
            'streaming_enabled' => false,
        ];

        $event = new SynapsePrePromptEvent(
            'Search for PHP',  // message
            [],                // options
            ['contents' => $promptContent, 'toolDefinitions' => []],    // prompt
            $config            // config
        );

        // Act
        $this->subscriber->onPrePrompt($event);

        // Expect the debug logger to be called with tool_usage populated
        $this->debugLogger->expects($this->once())
            ->method('logExchange')
            ->with(
                'test-id',
                $this->anything(),
                $this->callback(function (array $debugData) {
                    return isset($debugData['tool_usage']) 
                        && count($debugData['tool_usage']) === 1
                        && $debugData['tool_usage'][0]['tool_name'] === 'search'
                        && $debugData['tool_usage'][0]['tool_call_id'] === 'call_1'
                        && strpos($debugData['tool_usage'][0]['tool_result'], 'Results') !== false;
                })
            );

        // Act (complete event)
        $completedEvent = new SynapseExchangeCompletedEvent(
            'test-id',          // debugId
            'gpt-4',            // model
            'openai',           // provider
            [],                 // usage
            [],                 // safety
            true                // debugMode
        );

        $this->subscriber->onExchangeCompleted($completedEvent);
    }

    public function testEmptyToolUsageWhenNoToolsUsed(): void
    {
        // Arrange
        $promptContent = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ];

        $config = [
            'model' => 'gpt-4',
            'provider' => 'openai',
            'generation_config' => ['temperature' => 0.7, 'top_p' => 1.0, 'top_k' => null, 'max_output_tokens' => 2048],
            'thinking' => ['enabled' => false],
            'safety_settings' => ['enabled' => true],
            'streaming_enabled' => false,
        ];

        $event = new SynapsePrePromptEvent(
            'Hello',           // message
            [],                // options
            ['contents' => $promptContent, 'toolDefinitions' => []],    // prompt
            $config            // config
        );

        // Act
        $this->subscriber->onPrePrompt($event);

        // Expect empty tool_usage array
        $this->debugLogger->expects($this->once())
            ->method('logExchange')
            ->with(
                'test-id',
                $this->anything(),
                $this->callback(function (array $debugData) {
                    return isset($debugData['tool_usage']) && count($debugData['tool_usage']) === 0;
                })
            );

        // Act (complete event)
        $completedEvent = new SynapseExchangeCompletedEvent(
            'test-id',          // debugId
            'gpt-4',            // model
            'openai',           // provider
            [],                 // usage
            [],                 // safety
            true                // debugMode
        );

        $this->subscriber->onExchangeCompleted($completedEvent);
    }
}
