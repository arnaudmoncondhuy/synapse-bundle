<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ApiKeyProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\ChatService;
use ArnaudMoncondhuy\SynapseBundle\Service\Infra\GeminiClient;
use ArnaudMoncondhuy\SynapseBundle\Service\PromptBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

class ChatServiceTest extends TestCase
{
    private $geminiClient;
    private $promptBuilder;
    private $conversationHandler;
    private $cache;
    private $apiKeyProvider;
    private $chatService;
    private $tool;

    protected function setUp(): void
    {
        $this->geminiClient = $this->createMock(GeminiClient::class);
        $this->promptBuilder = $this->createMock(PromptBuilder::class);
        $this->conversationHandler = $this->createMock(ConversationHandlerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->apiKeyProvider = $this->createMock(ApiKeyProviderInterface::class);

        // Mock Tool
        $this->tool = $this->createMock(AiToolInterface::class);
        $this->tool->method('getName')->willReturn('test_tool');
        $this->tool->method('getInputSchema')->willReturn([]);

        $this->chatService = new ChatService(
            $this->geminiClient,
            $this->promptBuilder,
            $this->conversationHandler,
            [$this->tool],
            $this->cache,
            $this->apiKeyProvider
        );
    }

    public function testAskOrchestratesStandardTurn(): void
    {
        // 1. Setup Context
        $this->promptBuilder->method('buildSystemInstruction')->willReturn('SYSTEM');
        $this->conversationHandler->method('loadHistory')->willReturn([]);

        // 2. Expect Gemini Call
        $this->geminiClient->expects($this->once())
            ->method('generateContent')
            ->willReturn([
                'parts' => [['text' => 'Hello Human']],
            ]);

        // 3. Expect History Save
        $this->conversationHandler->expects($this->once())
            ->method('saveHistory')
            ->with($this->callback(function ($history) {
                // Should contain user message and model response
                return 2 === count($history)
                    && 'user' === $history[0]['role']
                    && 'model' === $history[1]['role'];
            }));

        // Act
        $result = $this->chatService->ask('Hi', ['api_key' => 'KEY']);

        // Assert
        $this->assertEquals('Hello Human', $result['answer']);
    }

    public function testAskHandlesToolExecution(): void
    {
        // Scenario:
        // Turn 1: Gemini calls function 'test_tool'
        // Turn 2: Service executes tool, sends result back to Gemini
        // Turn 3: Gemini returns final answer

        $this->promptBuilder->method('buildSystemInstruction')->willReturn('SYSTEM');

        // Configure Gemini Mock for 2 calls
        $this->geminiClient->expects($this->exactly(2))
            ->method('generateContent')
            ->willReturnOnConsecutiveCalls(
                // Response 1: I need to use a tool
                [
                    'parts' => [
                        ['functionCall' => ['name' => 'test_tool', 'args' => ['foo' => 'bar']]],
                    ],
                ],
                // Response 2: Final answer after tool result
                [
                    'parts' => [['text' => 'The tool says: SUCCESS']],
                ]
            );

        // Configure Tool Execution
        $this->tool->expects($this->once())
            ->method('execute')
            ->with(['foo' => 'bar'])
            ->willReturn('SUCCESS');

        // Act
        $result = $this->chatService->ask('Do something', ['api_key' => 'KEY']);

        // Assert
        $this->assertEquals('The tool says: SUCCESS', $result['answer']);
    }

    public function testAskUsesInjectedApiKeyByDefault(): void
    {
        $this->promptBuilder->method('buildSystemInstruction')->willReturn('SYSTEM');
        $this->conversationHandler->method('loadHistory')->willReturn([]);
        $this->apiKeyProvider->method('provideApiKey')->willReturn('DYNAMIC_KEY');

        $this->geminiClient->expects($this->once())
            ->method('generateContent')
            ->with('SYSTEM', $this->anything(), 'DYNAMIC_KEY') // Verification of the dynamic key
            ->willReturn([
                'parts' => [['text' => 'Hello']],
            ]);

        $this->chatService->ask('Hi'); // No api_key in options
    }
}
