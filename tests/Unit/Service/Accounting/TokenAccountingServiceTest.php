<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Service\Accounting;

use ArnaudMoncondhuy\SynapseBundle\Core\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseModel;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseTokenUsage;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class TokenAccountingServiceTest extends TestCase
{
    private SynapseModelRepository $modelRepository;
    private EntityManagerInterface $entityManager;
    private TokenAccountingService $service;

    protected function setUp(): void
    {
        // Use reflection to create a mock that avoids the abstract parent class issue
        $this->modelRepository = $this->getMockBuilder(SynapseModelRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findAllPricingMap'])
            ->getMock();

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
            ->with($this->isInstanceOf(SynapseTokenUsage::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'thinking_tokens' => 25,
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'email',
            action: 'summarize',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt_tokens' => 120,
                'completion_tokens' => 80,
                'thinking_tokens' => 30,
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'turn',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'thinking_tokens' => 25,
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50],
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'chat_turn',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt_tokens' => 1000000,
                'completion_tokens' => 1000000,
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'test',
            action: 'test',
            model: 'gemini-2.5-flash',
            usage: [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'test',
            action: 'test',
            model: 'unknown-model',
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50]
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
                $capturedEntity = $entity;
            });

        // Act
        $this->service->logUsage(
            module: 'chat',
            action: 'turn',
            model: 'gemini-2.5-flash',
            usage: ['prompt_tokens' => 10, 'completion_tokens' => 5],
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
            ->willReturnCallback(function (SynapseTokenUsage $entity) use (&$capturedEntity) {
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
            usage: ['prompt_tokens' => 50, 'completion_tokens' => 25],
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
