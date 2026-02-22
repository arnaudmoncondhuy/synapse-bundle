<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Entity;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $message = $this->createMessage();

        $this->assertIsString($message->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $message->getCreatedAt());
        $this->assertFalse($message->isBlocked());
        $this->assertNull($message->getPromptTokens());
        $this->assertNull($message->getCompletionTokens());
        $this->assertNull($message->getThinkingTokens());
        $this->assertNull($message->getTotalTokens());
        $this->assertNull($message->getSafetyRatings());
        $this->assertNull($message->getFeedback());
        $this->assertNull($message->getMetadata());
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
    }

    public function testCalculateTotalTokens(): void
    {
        $message = $this->createMessage();
        $message->setPromptTokens(100);
        $message->setCompletionTokens(50);
        $message->setThinkingTokens(25);
        $message->calculateTotalTokens();

        $this->assertEquals(175, $message->getTotalTokens());
    }

    public function testCalculateTotalTokensWithNulls(): void
    {
        $message = $this->createMessage();
        $message->setPromptTokens(100);
        // completionTokens et thinkingTokens restent null
        $message->calculateTotalTokens();

        $this->assertEquals(100, $message->getTotalTokens());
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

    public function testGetMetadataValue(): void
    {
        $message = $this->createMessage();
        $message->setMetadata(['debug_id' => 'abc123']);

        $this->assertEquals('abc123', $message->getMetadataValue('debug_id'));
        $this->assertNull($message->getMetadataValue('missing'));
        $this->assertEquals('default', $message->getMetadataValue('missing', 'default'));
    }

    public function testSetMetadataValueOnNullMetadata(): void
    {
        $message = $this->createMessage();
        $message->setMetadataValue('key', 'value');

        $this->assertEquals('value', $message->getMetadataValue('key'));
    }

    // --- Role helpers ---

    public function testIsUser(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::USER);

        $this->assertTrue($message->isUser());
        $this->assertFalse($message->isModel());
        $this->assertFalse($message->isSystem());
        $this->assertFalse($message->isFunction());
    }

    public function testIsModel(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::MODEL);

        $this->assertTrue($message->isModel());
        $this->assertFalse($message->isUser());
    }

    public function testIsSystem(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::SYSTEM);

        $this->assertTrue($message->isSystem());
    }

    public function testIsFunction(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::FUNCTION);

        $this->assertTrue($message->isFunction());
    }

    public function testIsDisplayableForUser(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::USER);

        $this->assertTrue($message->isDisplayable());
    }

    public function testIsDisplayableForModel(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::MODEL);

        $this->assertTrue($message->isDisplayable());
    }

    public function testIsNotDisplayableForSystem(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::SYSTEM);

        $this->assertFalse($message->isDisplayable());
    }

    public function testIsNotDisplayableForFunction(): void
    {
        $message = $this->createMessage();
        $message->setRole(MessageRole::FUNCTION);

        $this->assertFalse($message->isDisplayable());
    }

    // --- Feedback ---

    public function testLikeMessage(): void
    {
        $message = $this->createMessage();
        $message->likeMessage();

        $this->assertEquals(1, $message->getFeedback());
        $this->assertEquals('positive', $message->getFeedbackRating());
    }

    public function testDislikeMessage(): void
    {
        $message = $this->createMessage();
        $message->dislikeMessage();

        $this->assertEquals(-1, $message->getFeedback());
        $this->assertEquals('negative', $message->getFeedbackRating());
    }

    public function testResetFeedback(): void
    {
        $message = $this->createMessage();
        $message->likeMessage();
        $message->resetFeedback();

        $this->assertNull($message->getFeedback());
        $this->assertNull($message->getFeedbackRating());
    }

    public function testFeedbackRatingNullByDefault(): void
    {
        $message = $this->createMessage();

        $this->assertNull($message->getFeedbackRating());
    }

    // --- Decrypted content ---

    public function testGetDecryptedContentFallsBackToContent(): void
    {
        $message = $this->createMessage();
        $message->setContent('plain text');

        $this->assertEquals('plain text', $message->getDecryptedContent());
    }

    public function testGetDecryptedContentReturnsDecryptedIfSet(): void
    {
        $message = $this->createMessage();
        $message->setContent('encrypted_blob');
        $message->setDecryptedContent('decrypted text');

        $this->assertEquals('decrypted text', $message->getDecryptedContent());
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
