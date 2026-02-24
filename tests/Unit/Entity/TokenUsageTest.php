<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Entity;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseTokenUsage;
use PHPUnit\Framework\TestCase;

class TokenUsageTest extends TestCase
{
    /**
     * Test que SynapseTokenUsage peut être créée avec les propriétés par défaut.
     */
    public function testTokenUsageCreation(): void
    {
        // Act
        $usage = new SynapseTokenUsage();

        // Assert
        $this->assertNull($usage->getId());
        $this->assertEquals(0, $usage->getPromptTokens());
        $this->assertEquals(0, $usage->getCompletionTokens());
        $this->assertEquals(0, $usage->getThinkingTokens());
        $this->assertEquals(0, $usage->getTotalTokens());
    }

    /**
     * Test setModule et getModule.
     */
    public function testSetAndGetModule(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setModule('chat');

        // Assert
        $this->assertEquals('chat', $usage->getModule());
    }

    /**
     * Test setAction et getAction.
     */
    public function testSetAndGetAction(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setAction('chat_turn');

        // Assert
        $this->assertEquals('chat_turn', $usage->getAction());
    }

    /**
     * Test setModel et getModel.
     */
    public function testSetAndGetModel(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setModel('gemini-2.5-flash');

        // Assert
        $this->assertEquals('gemini-2.5-flash', $usage->getModel());
    }

    /**
     * Test setPromptTokens et getPromptTokens.
     */
    public function testSetAndGetPromptTokens(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setPromptTokens(100);

        // Assert
        $this->assertEquals(100, $usage->getPromptTokens());
    }

    /**
     * Test setCompletionTokens et getCompletionTokens.
     */
    public function testSetAndGetCompletionTokens(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setCompletionTokens(50);

        // Assert
        $this->assertEquals(50, $usage->getCompletionTokens());
    }

    /**
     * Test setThinkingTokens et getThinkingTokens.
     */
    public function testSetAndGetThinkingTokens(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setThinkingTokens(25);

        // Assert
        $this->assertEquals(25, $usage->getThinkingTokens());
    }

    /**
     * Test calculateTotalTokens avec tous les types.
     */
    public function testCalculateTotalTokens(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $usage->setPromptTokens(100);
        $usage->setCompletionTokens(50);
        $usage->setThinkingTokens(25);

        // Act
        $usage->calculateTotalTokens();

        // Assert
        $this->assertEquals(175, $usage->getTotalTokens());
    }

    /**
     * Test calculateTotalTokens avec seulement prompt.
     */
    public function testCalculateTotalTokensWithOnlyPrompt(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $usage->setPromptTokens(100);

        // Act
        $usage->calculateTotalTokens();

        // Assert
        $this->assertEquals(100, $usage->getTotalTokens());
    }

    /**
     * Test setUserId et getUserId.
     */
    public function testSetAndGetUserId(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $userId = 'user-12345';

        // Act
        $usage->setUserId($userId);

        // Assert
        $this->assertEquals($userId, $usage->getUserId());
    }

    /**
     * Test setConversationId et getConversationId.
     */
    public function testSetAndGetConversationId(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $conversationId = 'conv-67890';

        // Act
        $usage->setConversationId($conversationId);

        // Assert
        $this->assertEquals($conversationId, $usage->getConversationId());
    }

    /**
     * Test createdAt est initialisé automatiquement.
     */
    public function testCreatedAtIsAutoInitialized(): void
    {
        // Act
        $usage = new SynapseTokenUsage();

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $usage->getCreatedAt());
    }

    /**
     * Test setMetadata et getMetadata.
     */
    public function testSetAndGetMetadata(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $metadata = [
            'cost' => 0.50,
            'pricing' => ['input' => 0.30, 'output' => 2.50],
            'debug_id' => 'abc123',
        ];

        // Act
        $usage->setMetadata($metadata);

        // Assert
        $this->assertEquals($metadata, $usage->getMetadata());
    }

    /**
     * Test getMetadataValue avec clé existante.
     */
    public function testGetMetadataValueWithExistingKey(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $usage->setMetadata(['cost' => 1.25, 'debug_id' => 'xyz']);

        // Act
        $cost = $usage->getMetadataValue('cost');
        $debugId = $usage->getMetadataValue('debug_id');

        // Assert
        $this->assertEquals(1.25, $cost);
        $this->assertEquals('xyz', $debugId);
    }

    /**
     * Test getMetadataValue avec clé manquante retourne null.
     */
    public function testGetMetadataValueMissingKeyReturnsNull(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $usage->setMetadata(['cost' => 1.0]);

        // Act
        $result = $usage->getMetadataValue('missing_key');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test getMetadataValue avec valeur par défaut.
     */
    public function testGetMetadataValueWithDefault(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $usage->setMetadata(['cost' => 1.0]);

        // Act
        $result = $usage->getMetadataValue('missing_key', 'default');

        // Assert
        $this->assertEquals('default', $result);
    }

    /**
     * Test setMetadataValue sur null metadata.
     */
    public function testSetMetadataValueOnNullMetadata(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setMetadataValue('new_key', 'new_value');

        // Assert
        $this->assertEquals('new_value', $usage->getMetadataValue('new_key'));
    }

    /**
     * Test avec un usage complet typique.
     */
    public function testCompleteTokenUsageFlow(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->setModule('chat')
            ->setAction('chat_turn')
            ->setModel('gemini-2.5-flash')
            ->setPromptTokens(200)
            ->setCompletionTokens(100)
            ->setThinkingTokens(50)
            ->setUserId('user-123')
            ->setConversationId('conv-456')
            ->setMetadata(['cost' => 0.85]);

        $usage->calculateTotalTokens();

        // Assert
        $this->assertEquals('chat', $usage->getModule());
        $this->assertEquals('chat_turn', $usage->getAction());
        $this->assertEquals('gemini-2.5-flash', $usage->getModel());
        $this->assertEquals(200, $usage->getPromptTokens());
        $this->assertEquals(100, $usage->getCompletionTokens());
        $this->assertEquals(50, $usage->getThinkingTokens());
        $this->assertEquals(350, $usage->getTotalTokens());
        $this->assertEquals('user-123', $usage->getUserId());
        $this->assertEquals('conv-456', $usage->getConversationId());
        $this->assertEquals(0.85, $usage->getMetadataValue('cost'));
    }

    /**
     * Test que les setters retournent $this.
     */
    public function testSettersReturnThis(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $result = $usage->setModule('test')
            ->setAction('test_action')
            ->setModel('test-model')
            ->setPromptTokens(10);

        // Assert
        $this->assertSame($usage, $result);
    }

    /**
     * Test zéro tokens total.
     */
    public function testZeroTokensTotal(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();

        // Act
        $usage->calculateTotalTokens();

        // Assert
        $this->assertEquals(0, $usage->getTotalTokens());
    }

    /**
     * Test avec grand nombre de tokens.
     */
    public function testLargeTokenCounts(): void
    {
        // Arrange
        $usage = new SynapseTokenUsage();
        $usage->setPromptTokens(1000000)
            ->setCompletionTokens(500000)
            ->setThinkingTokens(250000);

        // Act
        $usage->calculateTotalTokens();

        // Assert
        $this->assertEquals(1750000, $usage->getTotalTokens());
    }
}
