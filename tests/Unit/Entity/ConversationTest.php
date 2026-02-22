<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Entity;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
    /**
     * Crée une instance concrète de Conversation pour les tests.
     */
    private function createConversation(): Conversation
    {
        return new class extends Conversation {
            private ?ConversationOwnerInterface $owner = null;

            public function getOwner(): ?ConversationOwnerInterface
            {
                return $this->owner;
            }

            public function setOwner(ConversationOwnerInterface $owner): self
            {
                $this->owner = $owner;
                return $this;
            }
        };
    }

    /**
     * Test des valeurs par défaut à la création.
     */
    public function testDefaultValuesOnConstruction(): void
    {
        // Act
        $conversation = $this->createConversation();

        // Assert
        $this->assertIsString($conversation->getId());
        $this->assertNotEmpty($conversation->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getUpdatedAt());
        $this->assertEquals(ConversationStatus::ACTIVE, $conversation->getStatus());
        $this->assertNull($conversation->getTitle());
        $this->assertNull($conversation->getSummary());
        $this->assertCount(0, $conversation->getMessages());
    }

    /**
     * Test que l'ID est un ULID valide.
     */
    public function testIdIsValidUlid(): void
    {
        // Act
        $conversation = $this->createConversation();
        $id = $conversation->getId();

        // Assert
        $this->assertIsString($id);
        // ULID est un UUID format 36 caractères
        $this->assertEquals(36, strlen($id));
    }

    /**
     * Test que createdAt et updatedAt sont initialisés à la même valeur.
     */
    public function testCreatedAtAndUpdatedAtAreInitialized(): void
    {
        // Act
        $conversation = $this->createConversation();
        $createdAt = $conversation->getCreatedAt();
        $updatedAt = $conversation->getUpdatedAt();

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $createdAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $updatedAt);
        // Elles doivent être très proches (créées à la même seconde)
        $this->assertLessThan(1, abs($createdAt->getTimestamp() - $updatedAt->getTimestamp()));
    }

    /**
     * Test setTitle et getTitle.
     */
    public function testSetAndGetTitle(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $title = 'Discussion sobre les APIs LLM';

        // Act
        $conversation->setTitle($title);

        // Assert
        $this->assertEquals($title, $conversation->getTitle());
    }

    /**
     * Test setTitle retourne $this pour chainning.
     */
    public function testSetTitleReturnsThis(): void
    {
        // Arrange
        $conversation = $this->createConversation();

        // Act
        $result = $conversation->setTitle('Test');

        // Assert
        $this->assertSame($conversation, $result);
    }

    /**
     * Test setTitle avec null.
     */
    public function testSetTitleWithNull(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $conversation->setTitle('Initial');

        // Act
        $conversation->setTitle(null);

        // Assert
        $this->assertNull($conversation->getTitle());
    }

    /**
     * Test status par défaut est ACTIVE.
     */
    public function testDefaultStatusIsActive(): void
    {
        // Act
        $conversation = $this->createConversation();

        // Assert
        $this->assertEquals(ConversationStatus::ACTIVE, $conversation->getStatus());
    }

    /**
     * Test setStatus change le statut.
     */
    public function testSetStatusChangesStatus(): void
    {
        // Arrange
        $conversation = $this->createConversation();

        // Act
        $conversation->setStatus(ConversationStatus::ARCHIVED);

        // Assert
        $this->assertEquals(ConversationStatus::ARCHIVED, $conversation->getStatus());
    }

    /**
     * Test setSummary et getSummary.
     */
    public function testSetAndGetSummary(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $summary = 'Résumé généré par IA...';

        // Act
        $conversation->setSummary($summary);

        // Assert
        $this->assertEquals($summary, $conversation->getSummary());
    }

    /**
     * Test metadata storage and retrieval.
     */
    public function testSetAndGetMetadata(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $metadata = [
            'tags' => ['api', 'llm', 'python'],
            'context' => 'development',
        ];

        // Act
        $conversation->setMetadata($metadata);

        // Assert
        $this->assertEquals($metadata, $conversation->getMetadata());
    }

    /**
     * Test getMetadataValue with existing key.
     */
    public function testGetMetadataValueWithExistingKey(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $conversation->setMetadata(['key' => 'value']);

        // Act
        $result = $conversation->getMetadataValue('key');

        // Assert
        $this->assertEquals('value', $result);
    }

    /**
     * Test getMetadataValue with missing key returns null.
     */
    public function testGetMetadataValueMissingKeyReturnsNull(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $conversation->setMetadata(['other' => 'data']);

        // Act
        $result = $conversation->getMetadataValue('missing');

        // Assert
        $this->assertNull($result);
    }

    /**
     * Test getMetadataValue with default value.
     */
    public function testGetMetadataValueWithDefault(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $conversation->setMetadata(['other' => 'data']);

        // Act
        $result = $conversation->getMetadataValue('missing', 'default_value');

        // Assert
        $this->assertEquals('default_value', $result);
    }

    /**
     * Test setMetadataValue on null metadata.
     */
    public function testSetMetadataValueOnNullMetadata(): void
    {
        // Arrange
        $conversation = $this->createConversation();

        // Act
        $conversation->setMetadataValue('new_key', 'new_value');

        // Assert
        $this->assertEquals('new_value', $conversation->getMetadataValue('new_key'));
    }

    /**
     * Test messages collection is initialized.
     */
    public function testMessagesCollectionIsInitialized(): void
    {
        // Act
        $conversation = $this->createConversation();
        $messages = $conversation->getMessages();

        // Assert
        $this->assertCount(0, $messages);
        $this->assertIsIterable($messages);
    }

    /**
     * Test updateTimestamp modifie updatedAt.
     */
    public function testUpdateTimestampModifiesUpdatedAt(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $originalUpdatedAt = $conversation->getUpdatedAt();

        // Wait a tiny bit to ensure timestamp changes
        usleep(1000);

        // Act
        $conversation->updateTimestamp();
        $newUpdatedAt = $conversation->getUpdatedAt();

        // Assert
        $this->assertGreaterThan(
            $originalUpdatedAt->getTimestamp(),
            $newUpdatedAt->getTimestamp()
        );
    }

    /**
     * Test updateTimestamp ne change pas createdAt.
     */
    public function testUpdateTimestampDoesNotChangeCreatedAt(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $originalCreatedAt = $conversation->getCreatedAt();

        // Act
        usleep(1000);
        $conversation->updateTimestamp();

        // Assert
        $this->assertEquals(
            $originalCreatedAt->getTimestamp(),
            $conversation->getCreatedAt()->getTimestamp()
        );
    }

    /**
     * Test createdAt est immuable (DateTimeImmutable).
     */
    public function testCreatedAtIsImmutable(): void
    {
        // Act
        $conversation = $this->createConversation();
        $createdAt = $conversation->getCreatedAt();

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $createdAt);
    }

    /**
     * Test IDs sont uniques entre instances.
     */
    public function testEachConversationHasUniqueId(): void
    {
        // Act
        $conv1 = $this->createConversation();
        $conv2 = $this->createConversation();
        $conv3 = $this->createConversation();

        // Assert
        $this->assertNotEquals($conv1->getId(), $conv2->getId());
        $this->assertNotEquals($conv2->getId(), $conv3->getId());
        $this->assertNotEquals($conv1->getId(), $conv3->getId());
    }

    /**
     * Test setsetter chainning multiple properties.
     */
    public function testSetterChaining(): void
    {
        // Act
        $conversation = $this->createConversation()
            ->setTitle('Test')
            ->setStatus(ConversationStatus::ARCHIVED)
            ->setSummary('Summary');

        // Assert
        $this->assertEquals('Test', $conversation->getTitle());
        $this->assertEquals(ConversationStatus::ARCHIVED, $conversation->getStatus());
        $this->assertEquals('Summary', $conversation->getSummary());
    }

    /**
     * Test metadata with complex nested structure.
     */
    public function testMetadataWithComplexStructure(): void
    {
        // Arrange
        $conversation = $this->createConversation();
        $metadata = [
            'user' => ['id' => 123, 'role' => 'admin'],
            'tags' => ['important', 'urgent'],
            'config' => ['language' => 'fr', 'timezone' => 'Europe/Paris'],
        ];

        // Act
        $conversation->setMetadata($metadata);

        // Assert
        $this->assertEquals(123, $conversation->getMetadataValue('user')['id']);
        $this->assertContains('important', $conversation->getMetadataValue('tags'));
    }
}
