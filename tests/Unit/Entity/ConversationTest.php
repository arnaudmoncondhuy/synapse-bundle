<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Entity;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseBundle\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseBundle\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseBundle\Enum\RiskCategory;
use ArnaudMoncondhuy\SynapseBundle\Enum\RiskLevel;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $conversation = $this->createConversation();

        $this->assertIsString($conversation->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getUpdatedAt());
        $this->assertEquals(ConversationStatus::ACTIVE, $conversation->getStatus());
        $this->assertEquals(RiskLevel::NONE, $conversation->getRiskLevel());
        $this->assertNull($conversation->getTitle());
        $this->assertNull($conversation->getRiskCategory());
        $this->assertNull($conversation->getSummary());
        $this->assertNull($conversation->getMetadata());
        $this->assertEquals(0, $conversation->getMessageCount());
    }

    public function testSetTitle(): void
    {
        $conversation = $this->createConversation();
        $conversation->setTitle('Test Conversation');

        $this->assertEquals('Test Conversation', $conversation->getTitle());
    }

    public function testUpdateTimestamp(): void
    {
        $conversation = $this->createConversation();
        $originalUpdatedAt = $conversation->getUpdatedAt();

        usleep(1000);
        $conversation->updateTimestamp();

        $this->assertGreater($conversation->getUpdatedAt(), $originalUpdatedAt);
    }

    public function testSetStatus(): void
    {
        $conversation = $this->createConversation();
        $conversation->setStatus(ConversationStatus::ARCHIVED);

        $this->assertEquals(ConversationStatus::ARCHIVED, $conversation->getStatus());
    }

    public function testIsActiveByDefault(): void
    {
        $conversation = $this->createConversation();

        $this->assertTrue($conversation->isActive());
        $this->assertFalse($conversation->isArchived());
        $this->assertFalse($conversation->isDeleted());
    }

    public function testArchive(): void
    {
        $conversation = $this->createConversation();
        $conversation->archive();

        $this->assertEquals(ConversationStatus::ARCHIVED, $conversation->getStatus());
        $this->assertTrue($conversation->isArchived());
        $this->assertFalse($conversation->isActive());
    }

    public function testSoftDelete(): void
    {
        $conversation = $this->createConversation();
        $conversation->softDelete();

        $this->assertEquals(ConversationStatus::DELETED, $conversation->getStatus());
        $this->assertTrue($conversation->isDeleted());
        $this->assertFalse($conversation->isActive());
    }

    public function testRestoreFromDeleted(): void
    {
        $conversation = $this->createConversation();
        $conversation->softDelete();
        $this->assertTrue($conversation->isDeleted());

        $conversation->restore();

        $this->assertTrue($conversation->isActive());
        $this->assertEquals(ConversationStatus::ACTIVE, $conversation->getStatus());
    }

    public function testRestoreDoesNothingIfNotDeleted(): void
    {
        $conversation = $this->createConversation();
        $conversation->archive();

        $conversation->restore(); // Doit rester ARCHIVED

        $this->assertTrue($conversation->isArchived());
    }

    public function testSetRiskLevel(): void
    {
        $conversation = $this->createConversation();
        $conversation->setRiskLevel(RiskLevel::CRITICAL);

        $this->assertEquals(RiskLevel::CRITICAL, $conversation->getRiskLevel());
    }

    public function testSetRiskCategory(): void
    {
        $conversation = $this->createConversation();
        $conversation->setRiskCategory(RiskCategory::SUICIDE);

        $this->assertEquals(RiskCategory::SUICIDE, $conversation->getRiskCategory());
    }

    public function testHasRiskFalseByDefault(): void
    {
        $conversation = $this->createConversation();

        $this->assertFalse($conversation->hasRisk());
    }

    public function testHasRiskTrueOnWarning(): void
    {
        $conversation = $this->createConversation();
        $conversation->setRiskLevel(RiskLevel::WARNING);

        $this->assertTrue($conversation->hasRisk());
    }

    public function testHasRiskTrueOnCritical(): void
    {
        $conversation = $this->createConversation();
        $conversation->setRiskLevel(RiskLevel::CRITICAL);

        $this->assertTrue($conversation->hasRisk());
    }

    public function testSetSummary(): void
    {
        $conversation = $this->createConversation();
        $conversation->setSummary('Test summary');

        $this->assertEquals('Test summary', $conversation->getSummary());
    }

    public function testSetMetadata(): void
    {
        $conversation = $this->createConversation();
        $metadata = ['key' => 'value', 'nested' => ['a' => 'b']];
        $conversation->setMetadata($metadata);

        $this->assertEquals($metadata, $conversation->getMetadata());
    }

    public function testGetMetadataValue(): void
    {
        $conversation = $this->createConversation();
        $conversation->setMetadata(['name' => 'chat1', 'tags' => ['php']]);

        $this->assertEquals('chat1', $conversation->getMetadataValue('name'));
        $this->assertEquals(['php'], $conversation->getMetadataValue('tags'));
        $this->assertNull($conversation->getMetadataValue('missing'));
        $this->assertEquals('fallback', $conversation->getMetadataValue('missing', 'fallback'));
    }

    public function testSetMetadataValueOnNullMetadata(): void
    {
        $conversation = $this->createConversation();
        // metadata est null par défaut
        $conversation->setMetadataValue('key1', 'value1');

        $this->assertEquals('value1', $conversation->getMetadataValue('key1'));
    }

    public function testSetMetadataValuePreservesExisting(): void
    {
        $conversation = $this->createConversation();
        $conversation->setMetadataValue('a', '1');
        $conversation->setMetadataValue('b', '2');

        $this->assertEquals('1', $conversation->getMetadataValue('a'));
        $this->assertEquals('2', $conversation->getMetadataValue('b'));
    }

    public function testAddMessage(): void
    {
        $conversation = $this->createConversation();
        $message = $this->createMessage();
        $conversation->addMessage($message);

        $this->assertEquals(1, $conversation->getMessageCount());
    }

    public function testAddMessageNoDuplicates(): void
    {
        $conversation = $this->createConversation();
        $message = $this->createMessage();
        $conversation->addMessage($message);
        $conversation->addMessage($message);

        $this->assertEquals(1, $conversation->getMessageCount());
    }

    public function testRemoveMessage(): void
    {
        $conversation = $this->createConversation();
        $message = $this->createMessage();
        $conversation->addMessage($message);
        $conversation->removeMessage($message);

        $this->assertEquals(0, $conversation->getMessageCount());
    }

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

    private function createMessage(): Message
    {
        return new class extends Message {
            private ?Conversation $conversation = null;

            public function getConversation(): Conversation
            {
                return $this->conversation;
            }

            public function setConversation(Conversation $conversation): self
            {
                $this->conversation = $conversation;
                return $this;
            }
        };
    }
}
