<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseBundle\Core\Event\SynapseChunkReceivedEvent;
use PHPUnit\Framework\TestCase;

class SynapseChunkReceivedEventTest extends TestCase
{
    /**
     * Test création avec chunk minimal.
     */
    public function testEventCreationWithMinimalChunk(): void
    {
        // Arrange
        $chunk = ['text' => 'Hello'];

        // Act
        $event = new SynapseChunkReceivedEvent($chunk);

        // Assert
        $this->assertEquals($chunk, $event->getChunk());
        $this->assertEquals(0, $event->getTurn());
        $this->assertNull($event->getRawChunk());
    }

    /**
     * Test création avec chunk complet.
     */
    public function testEventCreationWithCompleteChunk(): void
    {
        // Arrange
        $chunk = [
            'text' => 'Response text',
            'thinking' => 'Internal reasoning',
            'function_calls' => [['name' => 'test_func']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'safety_ratings' => ['harassment' => 'NEGLIGIBLE'],
            'blocked' => false,
            'blocked_reason' => null,
        ];
        $turn = 1;
        $rawChunk = ['raw' => 'data'];

        // Act
        $event = new SynapseChunkReceivedEvent($chunk, $turn, $rawChunk);

        // Assert
        $this->assertEquals($chunk, $event->getChunk());
        $this->assertEquals($turn, $event->getTurn());
        $this->assertEquals($rawChunk, $event->getRawChunk());
    }

    /**
     * Test getText() retourne le texte ou null.
     */
    public function testGetTextReturnsTextOrNull(): void
    {
        // Arrange
        $chunk1 = ['text' => 'Hello'];
        $chunk2 = [];

        $event1 = new SynapseChunkReceivedEvent($chunk1);
        $event2 = new SynapseChunkReceivedEvent($chunk2);

        // Act
        $text1 = $event1->getText();
        $text2 = $event2->getText();

        // Assert
        $this->assertEquals('Hello', $text1);
        $this->assertNull($text2);
    }

    /**
     * Test getThinking() retourne la pensée ou null.
     */
    public function testGetThinkingReturnsThinkingOrNull(): void
    {
        // Arrange
        $chunk1 = ['thinking' => 'Let me think...'];
        $chunk2 = [];

        $event1 = new SynapseChunkReceivedEvent($chunk1);
        $event2 = new SynapseChunkReceivedEvent($chunk2);

        // Act
        $thinking1 = $event1->getThinking();
        $thinking2 = $event2->getThinking();

        // Assert
        $this->assertEquals('Let me think...', $thinking1);
        $this->assertNull($thinking2);
    }

    /**
     * Test getFunctionCalls() retourne array ou vide.
     */
    public function testGetFunctionCallsReturnsArrayOrEmpty(): void
    {
        // Arrange
        $chunk1 = ['function_calls' => [['name' => 'func1'], ['name' => 'func2']]];
        $chunk2 = [];

        $event1 = new SynapseChunkReceivedEvent($chunk1);
        $event2 = new SynapseChunkReceivedEvent($chunk2);

        // Act
        $calls1 = $event1->getFunctionCalls();
        $calls2 = $event2->getFunctionCalls();

        // Assert
        $this->assertCount(2, $calls1);
        $this->assertEquals('func1', $calls1[0]['name']);
        $this->assertEmpty($calls2);
    }

    /**
     * Test getUsage() retourne usage ou vide.
     */
    public function testGetUsageReturnsUsageOrEmpty(): void
    {
        // Arrange
        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 50];
        $chunk1 = ['usage' => $usage];
        $chunk2 = [];

        $event1 = new SynapseChunkReceivedEvent($chunk1);
        $event2 = new SynapseChunkReceivedEvent($chunk2);

        // Act
        $usage1 = $event1->getUsage();
        $usage2 = $event2->getUsage();

        // Assert
        $this->assertEquals($usage, $usage1);
        $this->assertEmpty($usage2);
    }

    /**
     * Test getSafetyRatings() retourne ratings ou vide.
     */
    public function testGetSafetyRatingsReturnRatingsOrEmpty(): void
    {
        // Arrange
        $ratings = ['harassment' => 'LOW', 'hate_speech' => 'NEGLIGIBLE'];
        $chunk1 = ['safety_ratings' => $ratings];
        $chunk2 = [];

        $event1 = new SynapseChunkReceivedEvent($chunk1);
        $event2 = new SynapseChunkReceivedEvent($chunk2);

        // Act
        $ratings1 = $event1->getSafetyRatings();
        $ratings2 = $event2->getSafetyRatings();

        // Assert
        $this->assertEquals($ratings, $ratings1);
        $this->assertEmpty($ratings2);
    }

    /**
     * Test isBlocked() retourne booléen.
     */
    public function testIsBlockedReturnsBool(): void
    {
        // Arrange
        $chunk1 = ['blocked' => true];
        $chunk2 = ['blocked' => false];
        $chunk3 = [];

        $event1 = new SynapseChunkReceivedEvent($chunk1);
        $event2 = new SynapseChunkReceivedEvent($chunk2);
        $event3 = new SynapseChunkReceivedEvent($chunk3);

        // Act
        $blocked1 = $event1->isBlocked();
        $blocked2 = $event2->isBlocked();
        $blocked3 = $event3->isBlocked();

        // Assert
        $this->assertTrue($blocked1);
        $this->assertFalse($blocked2);
        $this->assertFalse($blocked3);  // Default false
    }

    /**
     * Test getBlockedReason() retourne raison ou null.
     */
    public function testGetBlockedReasonReturnsReasonOrNull(): void
    {
        // Arrange
        $chunk1 = ['blocked_reason' => 'harcèlement'];
        $chunk2 = ['blocked_reason' => null];
        $chunk3 = [];

        $event1 = new SynapseChunkReceivedEvent($chunk1);
        $event2 = new SynapseChunkReceivedEvent($chunk2);
        $event3 = new SynapseChunkReceivedEvent($chunk3);

        // Act
        $reason1 = $event1->getBlockedReason();
        $reason2 = $event2->getBlockedReason();
        $reason3 = $event3->getBlockedReason();

        // Assert
        $this->assertEquals('harcèlement', $reason1);
        $this->assertNull($reason2);
        $this->assertNull($reason3);
    }

    /**
     * Test getTurn() retourne le numéro du tour.
     */
    public function testGetTurnReturnsCorrectTurn(): void
    {
        // Arrange
        $chunk = ['text' => 'Response'];

        // Act
        $event0 = new SynapseChunkReceivedEvent($chunk, 0);
        $event1 = new SynapseChunkReceivedEvent($chunk, 1);
        $event5 = new SynapseChunkReceivedEvent($chunk, 5);

        // Assert
        $this->assertEquals(0, $event0->getTurn());
        $this->assertEquals(1, $event1->getTurn());
        $this->assertEquals(5, $event5->getTurn());
    }

    /**
     * Test getRawChunk() retourne raw ou null.
     */
    public function testGetRawChunkReturnsRawOrNull(): void
    {
        // Arrange
        $chunk = ['text' => 'Response'];
        $raw = ['provider' => 'gemini', 'model' => 'gemini-2.5-flash'];

        $event1 = new SynapseChunkReceivedEvent($chunk, 0, $raw);
        $event2 = new SynapseChunkReceivedEvent($chunk, 0, null);
        $event3 = new SynapseChunkReceivedEvent($chunk);

        // Act
        $rawChunk1 = $event1->getRawChunk();
        $rawChunk2 = $event2->getRawChunk();
        $rawChunk3 = $event3->getRawChunk();

        // Assert
        $this->assertEquals($raw, $rawChunk1);
        $this->assertNull($rawChunk2);
        $this->assertNull($rawChunk3);
    }

    /**
     * Test avec un chunk contenant du texte et de la pensée.
     */
    public function testChunkWithTextAndThinking(): void
    {
        // Arrange
        $chunk = [
            'text' => 'Final answer',
            'thinking' => 'Internal analysis...',
        ];

        // Act
        $event = new SynapseChunkReceivedEvent($chunk);

        // Assert
        $this->assertEquals('Final answer', $event->getText());
        $this->assertEquals('Internal analysis...', $event->getThinking());
    }

    /**
     * Test avec un chunk bloqué contenant une raison.
     */
    public function testBlockedChunkWithReason(): void
    {
        // Arrange
        $chunk = [
            'blocked' => true,
            'blocked_reason' => 'contenu violent',
            'text' => null,
        ];

        // Act
        $event = new SynapseChunkReceivedEvent($chunk);

        // Assert
        $this->assertTrue($event->isBlocked());
        $this->assertEquals('contenu violent', $event->getBlockedReason());
        $this->assertNull($event->getText());
    }

    /**
     * Test avec fonction calls et usage.
     */
    public function testChunkWithFunctionCallsAndUsage(): void
    {
        // Arrange
        $chunk = [
            'function_calls' => [
                [
                    'name' => 'get_weather',
                    'arguments' => ['location' => 'Paris'],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 150,
                'completion_tokens' => 75,
            ],
        ];

        // Act
        $event = new SynapseChunkReceivedEvent($chunk);

        // Assert
        $calls = $event->getFunctionCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('get_weather', $calls[0]['name']);

        $usage = $event->getUsage();
        $this->assertEquals(150, $usage['prompt_tokens']);
        $this->assertEquals(75, $usage['completion_tokens']);
    }

    /**
     * Test que le chunk original n'est pas modifié.
     */
    public function testOriginalChunkIsNotModified(): void
    {
        // Arrange
        $chunk = ['text' => 'Original'];
        $originalChunk = $chunk;

        // Act
        $event = new SynapseChunkReceivedEvent($chunk);

        // Assert
        $this->assertEquals($originalChunk, $event->getChunk());
        $this->assertEquals('Original', $chunk['text']);
    }
}
