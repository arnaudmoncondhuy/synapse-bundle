<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseBundle\Core\Chat\PromptBuilder;
use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseBundle\Core\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Conversation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ChatServiceTest extends TestCase
{
    private LlmClientRegistry $llmRegistry;
    private PromptBuilder $promptBuilder;
    private ConfigProviderInterface $configProvider;
    private EntityManagerInterface $em;
    private EventDispatcherInterface $dispatcher;
    private ConversationManager $conversationManager;
    private ChatService $chatService;

    protected function setUp(): void
    {
        $this->llmRegistry = $this->createMock(LlmClientRegistry::class);
        $this->promptBuilder = $this->createMock(PromptBuilder::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->conversationManager = $this->createMock(ConversationManager::class);

        $this->chatService = new ChatService(
            $this->llmRegistry,
            $this->promptBuilder,
            [],
            $this->configProvider,
            $this->em,
            $this->dispatcher,
            $this->conversationManager,
        );
    }

    /**
     * Test que ask() retourne un array avec les clés attendues.
     */
    public function testAskReturnsArrayWithExpectedKeys(): void
    {
        // Arrange
        $message = 'Hello, how are you?';
        $options = ['debug' => false];

        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) {
                if ($event instanceof SynapsePrePromptEvent) {
                    $event->setPrompt([
                        'contents' => [
                            ['role' => 'system', 'content' => 'You are helpful'],
                            ['role' => 'user', 'content' => 'Hello'],
                        ],
                        'toolDefinitions' => [],
                    ]);
                    $event->setConfig([
                        'debug_mode' => false,
                        'streaming_enabled' => false,
                    ]);
                }
                return $event;
            });

        // Mock client
        $mockClient = $this->createMock(\ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface::class);
        $mockClient->method('generateContent')
            ->willReturn([
                'text' => 'I am doing well!',
                'thinking' => null,
                'function_calls' => [],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'safety_ratings' => [],
                'blocked' => false,
                'blocked_reason' => null,
            ]);

        $this->llmRegistry->method('getClient')
            ->willReturn($mockClient);

        // Act
        $result = $this->chatService->ask($message, $options);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
    }

    /**
     * Test que ask() avec reset_conversation et message vide retourne vide.
     */
    public function testAskWithResetConversationReturnsEmpty(): void
    {
        // Arrange
        $message = '';
        $options = ['reset_conversation' => true];

        // Act
        $result = $this->chatService->ask($message, $options);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertEmpty($result['answer']);
    }

    /**
     * Test que ask() dispatche le SynapsePrePromptEvent.
     */
    public function testAskDispatchesSynapsePrePromptEvent(): void
    {
        // Arrange
        $message = 'Test message';
        $prePromptEventDispatched = false;

        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) use (&$prePromptEventDispatched) {
                if ($event instanceof SynapsePrePromptEvent) {
                    $prePromptEventDispatched = true;
                    $event->setPrompt([
                        'contents' => [['role' => 'user', 'content' => 'Test']],
                        'toolDefinitions' => [],
                    ]);
                    $event->setConfig(['streaming_enabled' => false, 'debug_mode' => false]);
                }
                return $event;
            });

        $mockClient = $this->createMock(\ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface::class);
        $mockClient->method('generateContent')
            ->willReturn([
                'text' => 'Response',
                'thinking' => null,
                'function_calls' => [],
                'usage' => [],
                'safety_ratings' => [],
                'blocked' => false,
                'blocked_reason' => null,
            ]);

        $this->llmRegistry->method('getClient')
            ->willReturn($mockClient);

        // Act
        $result = $this->chatService->ask($message);

        // Assert
        $this->assertTrue($prePromptEventDispatched, 'SynapsePrePromptEvent should be dispatched');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
    }

    /**
     * Test que ask() utilise le client LLM enregistré.
     */
    public function testAskUsesRegisteredLlmClient(): void
    {
        // Arrange
        $message = 'Hello';

        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) {
                if ($event instanceof SynapsePrePromptEvent) {
                    $event->setPrompt([
                        'contents' => [['role' => 'user', 'content' => 'Hello']],
                        'toolDefinitions' => [],
                    ]);
                    $event->setConfig(['streaming_enabled' => false, 'debug_mode' => false]);
                }
                return $event;
            });

        $mockClient = $this->createMock(\ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface::class);
        $mockClient->expects($this->once())
            ->method('generateContent')
            ->willReturn([
                'text' => 'Hi!',
                'thinking' => null,
                'function_calls' => [],
                'usage' => [],
                'safety_ratings' => [],
                'blocked' => false,
                'blocked_reason' => null,
            ]);

        $this->llmRegistry->expects($this->once())
            ->method('getClient')
            ->willReturn($mockClient);

        // Act
        $this->chatService->ask($message);

        // Client.generateContent() was already expected and verified
    }

    /**
     * Test que ask() traite les chunks de manière itérative.
     */
    public function testAskAccumulatesChunksIntoAnswer(): void
    {
        // Arrange
        $message = 'Test';

        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) {
                if ($event instanceof SynapsePrePromptEvent) {
                    $event->setPrompt([
                        'contents' => [['role' => 'user', 'content' => 'Test']],
                        'toolDefinitions' => [],
                    ]);
                    $event->setConfig(['streaming_enabled' => false, 'debug_mode' => false]);
                }
                return $event;
            });

        $mockClient = $this->createMock(\ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface::class);
        $mockClient->method('generateContent')
            ->willReturn([
                'text' => 'This is a complete answer.',
                'thinking' => null,
                'function_calls' => [],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
                'safety_ratings' => [],
                'blocked' => false,
                'blocked_reason' => null,
            ]);

        $this->llmRegistry->method('getClient')
            ->willReturn($mockClient);

        // Act
        $result = $this->chatService->ask($message);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('answer', $result);
        $this->assertStringContainsString('answer', $result['answer']);
    }

    /**
     * Test avec debug mode activé.
     */
    public function testAskWithDebugModeEnabled(): void
    {
        // Arrange
        $message = 'Debug test';
        $options = ['debug' => true];

        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) {
                if ($event instanceof SynapsePrePromptEvent) {
                    $event->setPrompt([
                        'contents' => [['role' => 'user', 'content' => 'Debug test']],
                        'toolDefinitions' => [],
                    ]);
                    $event->setConfig([
                        'streaming_enabled' => false,
                        'debug_mode' => true,
                    ]);
                }
                return $event;
            });

        $mockClient = $this->createMock(\ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface::class);
        $mockClient->method('generateContent')
            ->willReturn([
                'text' => 'Debug response',
                'thinking' => null,
                'function_calls' => [],
                'usage' => [],
                'safety_ratings' => [],
                'blocked' => false,
                'blocked_reason' => null,
            ]);

        $this->llmRegistry->method('getClient')
            ->willReturn($mockClient);

        // Act
        $result = $this->chatService->ask($message, $options);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('debug_id', $result);
        $this->assertNotNull($result['debug_id']);
    }

    /**
     * Test avec callbacks de statut.
     */
    public function testAskWithStatusUpdateCallback(): void
    {
        // Arrange
        $message = 'Test';
        $statusUpdates = [];
        $onStatusUpdate = function (string $msg, string $step) use (&$statusUpdates) {
            $statusUpdates[] = ['message' => $msg, 'step' => $step];
        };

        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) {
                if ($event instanceof SynapsePrePromptEvent) {
                    $event->setPrompt([
                        'contents' => [['role' => 'user', 'content' => 'Test']],
                        'toolDefinitions' => [],
                    ]);
                    $event->setConfig(['streaming_enabled' => false, 'debug_mode' => false]);
                }
                return $event;
            });

        $mockClient = $this->createMock(\ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface::class);
        $mockClient->method('generateContent')
            ->willReturn([
                'text' => 'Response',
                'thinking' => null,
                'function_calls' => [],
                'usage' => [],
                'safety_ratings' => [],
                'blocked' => false,
                'blocked_reason' => null,
            ]);

        $this->llmRegistry->method('getClient')
            ->willReturn($mockClient);

        // Act
        $this->chatService->ask($message, [], $onStatusUpdate);

        // Assert
        $this->assertNotEmpty($statusUpdates);
        $this->assertEquals('thinking', $statusUpdates[0]['step']);
    }

    /**
     * Test que le format OpenAI canonical est maintenu dans les prompts.
     */
    public function testAskMaintainsOpenAiCanonicalFormat(): void
    {
        // Arrange
        $message = 'Format test';
        $expectedPrompt = [
            'contents' => [
                ['role' => 'system', 'content' => 'System instruction'],
                ['role' => 'user', 'content' => 'Format test'],
            ],
            'toolDefinitions' => [],
        ];

        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) use ($expectedPrompt) {
                if ($event instanceof SynapsePrePromptEvent) {
                    $event->setPrompt($expectedPrompt);
                    $event->setConfig(['streaming_enabled' => false, 'debug_mode' => false]);
                }
                return $event;
            });

        $capturedContents = null;
        $mockClient = $this->createMock(\ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface::class);
        $mockClient->method('generateContent')
            ->willReturnCallback(function (array $contents, array $tools = [], ?string $model = null, array $options = []) use (&$capturedContents) {
                $capturedContents = $contents;
                return [
                    'text' => 'Response',
                    'thinking' => null,
                    'function_calls' => [],
                    'usage' => [],
                    'safety_ratings' => [],
                    'blocked' => false,
                    'blocked_reason' => null,
                ];
            });

        $this->llmRegistry->method('getClient')
            ->willReturn($mockClient);

        // Act
        $this->chatService->ask($message);

        // Assert
        $this->assertIsArray($capturedContents);
        // Verify OpenAI format: each item has 'role' and 'content'
        foreach ($capturedContents as $item) {
            $this->assertArrayHasKey('role', $item);
            $this->assertArrayHasKey('content', $item);
        }
    }

    /**
     * Test que resetConversation appelle bien le manager.
     */
    public function testResetConversationCallsManager(): void
    {
        // Arrange
        $mockConversation = $this->createMock(Conversation::class);
        $this->conversationManager->expects($this->once())
            ->method('getCurrentConversation')
            ->willReturn($mockConversation);

        $this->conversationManager->expects($this->once())
            ->method('deleteConversation')
            ->with($mockConversation);

        $this->conversationManager->expects($this->once())
            ->method('setCurrentConversation')
            ->with(null);

        // Act
        $this->chatService->resetConversation();
    }

    /**
     * Test que getConversationHistory retourne l'historique formaté.
     */
    public function testGetConversationHistoryReturnsFormattedHistory(): void
    {
        // Arrange
        $mockConversation = $this->createMock(Conversation::class);
        $expectedHistory = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi!'],
        ];

        $this->conversationManager->expects($this->once())
            ->method('getCurrentConversation')
            ->willReturn($mockConversation);

        $this->conversationManager->expects($this->once())
            ->method('getHistoryArray')
            ->with($mockConversation)
            ->willReturn($expectedHistory);

        // Act
        $history = $this->chatService->getConversationHistory();

        // Assert
        $this->assertEquals($expectedHistory, $history);
    }
}
