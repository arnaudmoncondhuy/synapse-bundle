<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Entity;

use ArnaudMoncondhuy\SynapseBundle\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Enum\MessageRole;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $message = $this->createMessage();

        $this->assertInstanceOf(\Symfony\Component\Uid\Ulid::class, $message->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $message->getCreatedAt());
        $this->assertFalse($message->isBlocked());
        $this->assertNull($message->getPromptTokens());
        $this->assertNull($message->getCompletionTokens());
        $this->assertNull($message->getThinkingTokens());
        $this->assertNull($message->getSafetyRatings());
    }

    public function testSetRole(): void
    {
        $message = $this->createMessage();

        $message->setRole(MessageRole::USER);

        $this->assertEquals(MessageRole::USER, $message->getRole());
    }

    public function testSetContent(): void
    {
        $message = $this->createMessage();

        $message->setContent('Hello, World!');

        $this->assertEquals('Hello, World!', $message->getContent());
    }

    public function testSetTokens(): void
    {
        $message = $this->createMessage();

        $message->setPromptTokens(100);
        $message->setCompletionTokens(50);
        $message->setThinkingTokens(25);

        $this->assertEquals(100, $message->getPromptTokens());
        $this->assertEquals(50, $message->getCompletionTokens());
        $this->assertEquals(25, $message->getThinkingTokens());
        $this->assertEquals(175, $message->getTotalTokens());
    }

    public function testSetSafetyRatings(): void
    {
        $message = $this->createMessage();

        $ratings = [
            'HARM_CATEGORY_HATE_SPEECH' => 'NEGLIGIBLE',
            'HARM_CATEGORY_DANGEROUS_CONTENT' => 'LOW',
        ];
        $message->setSafetyRatings($ratings);

        $this->assertEquals($ratings, $message->getSafetyRatings());
    }

    public function testSetBlocked(): void
    {
        $message = $this->createMessage();

        $message->setBlocked(true);

        $this->assertTrue($message->isBlocked());
    }

    public function testSetMetadata(): void
    {
        $message = $this->createMessage();

        $metadata = ['model' => 'gemini-2.5-flash', 'version' => '1.0'];
        $message->setMetadata($metadata);

        $this->assertEquals($metadata, $message->getMetadata());
    }

    private function createMessage(): Message
    {
        return new class extends Message {
            private $conversation = null;

            public function getConversation()
            {
                return $this->conversation;
            }

            public function setConversation($conversation): self
            {
                $this->conversation = $conversation;
                return $this;
            }
        };
    }
}
