<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Entity;

use ArnaudMoncondhuy\SynapseBundle\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseBundle\Enum\RiskLevel;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $conversation = new class extends Conversation {
            public function getOwner(): \ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface
            {
                return new class implements \ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface {
                    public function getIdentifier(): string { return 'test@example.com'; }
                    public function getDisplayName(): string { return 'Test User'; }
                };
            }

            public function setOwner(\ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface $owner): self
            {
                return $this;
            }
        };

        $this->assertInstanceOf(\Symfony\Component\Uid\Ulid::class, $conversation->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getUpdatedAt());
        $this->assertEquals(ConversationStatus::ACTIVE, $conversation->getStatus());
        $this->assertEquals(RiskLevel::NONE, $conversation->getRiskLevel());
        $this->assertNull($conversation->getTitle());
        $this->assertNull($conversation->getRiskCategory());
        $this->assertNull($conversation->getSummary());
    }

    public function testSetTitle(): void
    {
        $conversation = $this->createConversation();

        $conversation->setTitle('Test Conversation');

        $this->assertEquals('Test Conversation', $conversation->getTitle());
    }

    public function testTouch(): void
    {
        $conversation = $this->createConversation();

        $originalUpdatedAt = $conversation->getUpdatedAt();
        sleep(1);
        $conversation->touch();

        $this->assertGreaterThan($originalUpdatedAt, $conversation->getUpdatedAt());
    }

    public function testSetStatus(): void
    {
        $conversation = $this->createConversation();

        $conversation->setStatus(ConversationStatus::ARCHIVED);

        $this->assertEquals(ConversationStatus::ARCHIVED, $conversation->getStatus());
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

        $conversation->setRiskCategory('SUICIDE');

        $this->assertEquals('SUICIDE', $conversation->getRiskCategory());
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

    private function createConversation(): Conversation
    {
        return new class extends Conversation {
            public function getOwner(): \ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface
            {
                return new class implements \ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface {
                    public function getIdentifier(): string { return 'test@example.com'; }
                    public function getDisplayName(): string { return 'Test User'; }
                };
            }

            public function setOwner(\ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface $owner): self
            {
                return $this;
            }
        };
    }
}
