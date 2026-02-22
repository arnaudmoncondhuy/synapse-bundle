<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Accounting;

use ArnaudMoncondhuy\SynapseBundle\Core\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\TokenUsage;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TokenAccountingServiceTest extends TestCase
{
    private SynapseModelRepository $modelRepository;
    private EntityManagerInterface $entityManager;
    private TokenAccountingService $service;

    protected function setUp(): void
    {
        $this->modelRepository = $this->createMock(SynapseModelRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new TokenAccountingService(
            $this->modelRepository,
            $this->entityManager
        );
    }

    /**
     * Test basic logUsage with internal format tokens.
     */
    public function testLogUsageWithInternalFormat(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(TokenUsage::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt' => 100,
                'completion' => 50,
                'thinking' => 25,
            ]
        );

        // Assert
        // Verified through mock expectations
    }

    /**
     * Test logUsage with Vertex API format tokens.
     */
    public function testLogUsageWithVertexFormat(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'email',
            action: 'summarize',
            model: 'gemini-2.5-flash',
            usage: [
                'promptTokenCount' => 120,
                'candidatesTokenCount' => 80,
                'thoughtsTokenCount' => 30,
            ]
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $this->assertEquals(120, $capturedEntity->getPromptTokens());
        $this->assertEquals(80, $capturedEntity->getCompletionTokens());
    }

    /**
     * Test logUsage calculates total tokens correctly.
     */
    public function testLogUsageCalculatesTotalTokens(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'turn',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt' => 100,
                'completion' => 50,
                'thinking' => 25,
            ]
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $this->assertEquals(175, $capturedEntity->getTotalTokens());
    }

    /**
     * Test logUsage with user and conversation IDs.
     */
    public function testLogUsageWithUserAndConversationId(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            usage: ['prompt' => 100, 'completion' => 50],
            userId: '550e8400-e29b-41d4-a716-446655440000',
            conversationId: 'conv-123'
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $capturedEntity->getUserId());
        $this->assertEquals('conv-123', $capturedEntity->getConversationId());
    }

    /**
     * Test logUsage stores cost in metadata.
     */
    public function testLogUsageStoresCostInMetadata(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt' => 1000000,
                'completion' => 1000000,
            ]
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $metadata = $capturedEntity->getMetadata();
        $this->assertArrayHasKey('cost', $metadata);
        // (1M * 0.30 + 1M * 2.50) / 1M = 2.80
        $this->assertEquals(2.80, $metadata['cost']);
    }

    /**
     * Test logFromGeminiResponse extracts and logs usage.
     */
    public function testLogFromGeminiResponse(): void
    {
        // Arrange
        $response = [
            'usageMetadata' => [
                'promptTokenCount' => 120,
                'candidatesTokenCount' => 80,
                'thoughtsTokenCount' => 0,
            ],
            'debug_id' => 'debug-xyz',
            'candidates' => [
                ['finishReason' => 'STOP'],
            ],
        ];

        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logFromGeminiResponse(
            module: 'chat',
            action: 'turn',
            model: 'gemini-2.5-flash',
            geminiResponse: $response
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $this->assertEquals(120, $capturedEntity->getPromptTokens());
        $this->assertEquals(80, $capturedEntity->getCompletionTokens());

        $metadata = $capturedEntity->getMetadata();
        $this->assertEquals('debug-xyz', $metadata['debug_id']);
        $this->assertEquals('STOP', $metadata['finish_reason']);
    }

    /**
     * Test with zero tokens (edge case).
     */
    public function testLogUsageWithZeroTokens(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'test',
            action: 'test',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt' => 0,
                'completion' => 0,
            ]
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $this->assertEquals(0, $capturedEntity->getTotalTokens());
    }

    /**
     * Test with unknown model (defaults to 0 cost).
     */
    public function testLogUsageWithUnknownModel(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([]);  // No pricing info

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'test',
            action: 'test',
            model: 'unknown-model',
            usage: ['prompt' => 100, 'completion' => 50]
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $metadata = $capturedEntity->getMetadata();
        $this->assertEquals(0.0, $metadata['cost']);
    }

    /**
     * Test with integer user ID (should be converted to string).
     */
    public function testLogUsageConvertsIntUserIdToString(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'turn',
            model: 'gemini-2.5-flash',
            usage: ['prompt' => 10, 'completion' => 5],
            userId: 12345
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $this->assertEquals('12345', $capturedEntity->getUserId());
        $this->assertIsString($capturedEntity->getUserId());
    }

    /**
     * Test with metadata parameter.
     */
    public function testLogUsagePreservesProvidedMetadata(): void
    {
        // Arrange
        $this->modelRepository->method('findAllPricingMap')
            ->willReturn([
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            ]);

        $capturedEntity = null;
        $this->entityManager->method('persist')
            ->willReturnCallback(function (TokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        $customMetadata = [
            'custom_field' => 'custom_value',
            'request_id' => 'req-789',
        ];

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'turn',
            model: 'gemini-2.5-flash',
            usage: ['prompt' => 50, 'completion' => 25],
            metadata: $customMetadata
        );

        // Assert
        $this->assertNotNull($capturedEntity);
        $metadata = $capturedEntity->getMetadata();
        $this->assertEquals('custom_value', $metadata['custom_field']);
        $this->assertEquals('req-789', $metadata['request_id']);
        // Cost and pricing should be added
        $this->assertArrayHasKey('cost', $metadata);
        $this->assertArrayHasKey('pricing', $metadata);
    }
}
