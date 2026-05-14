<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Event;

use ArnaudMoncondhuy\SynapseCore\Brain\Event\BrainContextSubscriber;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\MemoryRetrieverInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalResult;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\ScoredNeuron;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class BrainContextSubscriberTest extends TestCase
{
    private function buildSemantic(string $subject, string $predicate = 'is', string $value = 'val'): SemanticNeuron
    {
        return new SemanticNeuron(
            firstSource: Uuid::v7(),
            subject: $subject,
            predicate: $predicate,
            value: $value,
        );
    }

    private function buildEvent(string $message, array $options = []): PromptEnrichEvent
    {
        return new PromptEnrichEvent(
            message: $message,
            options: $options,
            prompt: ['contents' => [['role' => 'system', 'content' => 'Initial system']]],
        );
    }

    public function testSubscribedToPromptEnrichWithPriority45(): void
    {
        $events = BrainContextSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(PromptEnrichEvent::class, $events);
        $this->assertSame(['onEnrich', 45], $events[PromptEnrichEvent::class]);
    }

    public function testSkipsOnStateless(): void
    {
        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->expects($this->never())->method('retrieve');

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('test', ['stateless' => true]);

        $sub->onEnrich($event);

        $this->assertSame([['role' => 'system', 'content' => 'Initial system']], $event->getPrompt()['contents']);
    }

    public function testSkipsOnEmptyMessage(): void
    {
        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->expects($this->never())->method('retrieve');

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('   ');

        $sub->onEnrich($event);
    }

    public function testInjectsNeuronsIntoSystemPrompt(): void
    {
        $neuron = $this->buildSemantic('ordinateur', 'is', 'cassé');

        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->method('retrieve')->willReturn(new RetrievalResult([
            new ScoredNeuron(neuron: $neuron, score: 0.85, depth: 0),
        ], ['seeds_count' => 1]));

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('problème ordinateur');

        $sub->onEnrich($event);

        $prompt = $event->getPrompt();
        $systemContent = $prompt['contents'][0]['content'] ?? '';
        $this->assertStringContainsString('Brain v3', $systemContent);
        $this->assertStringContainsString('ordinateur is cassé', $systemContent);
        $this->assertStringContainsString('score 0.85', $systemContent);
    }

    public function testStoresDebugInMetadata(): void
    {
        $neuron = $this->buildSemantic('X');

        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->method('retrieve')->willReturn(new RetrievalResult(
            [new ScoredNeuron(neuron: $neuron, score: 0.5, depth: 1)],
            ['seeds_count' => 2, 'reinforced_synapses_count' => 1],
        ));

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('test');

        $sub->onEnrich($event);

        $metadata = $event->getPrompt()['metadata'] ?? [];
        $this->assertArrayHasKey('brain_retrieval', $metadata);
        $this->assertSame(1, $metadata['brain_retrieval']['found']);
        $this->assertSame(2, $metadata['brain_retrieval']['debug']['seeds_count']);
    }

    public function testEmptyResultStillStoresMetadata(): void
    {
        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->method('retrieve')->willReturn(RetrievalResult::empty());

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('test');

        $sub->onEnrich($event);

        $metadata = $event->getPrompt()['metadata'] ?? [];
        $this->assertArrayHasKey('brain_retrieval', $metadata);
        $this->assertSame(0, $metadata['brain_retrieval']['found']);

        // System prompt non touché
        $systemContent = $event->getPrompt()['contents'][0]['content'] ?? '';
        $this->assertSame('Initial system', $systemContent);
    }

    public function testPassesUserIdAsUuidToRetriever(): void
    {
        $alice = Uuid::v7();
        $captured = null;

        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->method('retrieve')->willReturnCallback(static function (RetrievalQuery $q) use (&$captured) {
            $captured = $q;

            return RetrievalResult::empty();
        });

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('test', ['user_id' => $alice->toRfc4122()]);

        $sub->onEnrich($event);

        $this->assertNotNull($captured);
        $this->assertEquals($alice, $captured->ownerId);
    }

    public function testNullOwnerWhenNoUserAndNoTokenStorage(): void
    {
        $captured = null;

        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->method('retrieve')->willReturnCallback(static function (RetrievalQuery $q) use (&$captured) {
            $captured = $q;

            return RetrievalResult::empty();
        });

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('test');

        $sub->onEnrich($event);

        $this->assertNotNull($captured);
        $this->assertNull($captured->ownerId);
    }

    public function testRetrieverExceptionDoesNotBreakPipeline(): void
    {
        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->method('retrieve')->willThrowException(new \RuntimeException('DB down'));

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('test');

        $sub->onEnrich($event); // ne throw pas

        // Prompt initial préservé
        $systemContent = $event->getPrompt()['contents'][0]['content'] ?? '';
        $this->assertSame('Initial system', $systemContent);
    }

    public function testInvalidUserIdFallsBackToNull(): void
    {
        $captured = null;

        $retriever = $this->createMock(MemoryRetrieverInterface::class);
        $retriever->method('retrieve')->willReturnCallback(static function (RetrievalQuery $q) use (&$captured) {
            $captured = $q;

            return RetrievalResult::empty();
        });

        $sub = new BrainContextSubscriber($retriever);
        $event = $this->buildEvent('test', ['user_id' => 'not-a-uuid']);

        $sub->onEnrich($event);

        $this->assertNotNull($captured);
        $this->assertNull($captured->ownerId);
    }
}
