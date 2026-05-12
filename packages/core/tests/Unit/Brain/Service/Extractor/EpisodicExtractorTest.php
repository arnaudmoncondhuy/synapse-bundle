<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\EpisodicExtractor;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;

class EpisodicExtractorTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $episode
     *
     * @return array<string, mixed>
     */
    private function chatServiceResponse(?array $episode): array
    {
        return [
            'answer' => '',
            'debug_id' => null,
            'call_id' => null,
            'usage' => ['prompt' => 80, 'completion' => 40],
            'safety' => [],
            'model' => 'gemini-test',
            'preset_id' => null,
            'agent_id' => null,
            'generated_attachments' => [],
            'structured_output' => ['episode' => $episode],
        ];
    }

    public function testSupportedAreasReturnsEpisodic(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $extractor = new EpisodicExtractor($chatService);

        $this->assertSame([BrainArea::Episodic], $extractor->supportedAreas());
    }

    public function testExtractsEpisodeFromStructuredOutput(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'occurred_at' => '2026-04-11T14:30:00Z',
            'event_summary' => 'Rencontre Alice à la conférence',
            'actors' => ['Alice'],
            'location' => 'conférence',
        ]));

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', ['email' => 'on s\'est vu hier']);

        $result = $extractor->extract($source, BrainArea::Episodic);

        $this->assertSame(BrainArea::Episodic, $result->area);
        $this->assertCount(1, $result->neurons);
        $this->assertSame(1, $result->debug['extracted_count']);

        /** @var EpisodicNeuron $neuron */
        $neuron = $result->neurons[0];
        $this->assertInstanceOf(EpisodicNeuron::class, $neuron);
        $this->assertSame('2026-04-11T14:30:00+00:00', $neuron->getOccurredAt()->format('c'));
        $this->assertSame('Rencontre Alice à la conférence', $neuron->getEventSummary());
        $this->assertSame(['Alice'], $neuron->getActors());
        $this->assertSame('conférence', $neuron->getLocation());
        $this->assertSame($source->getId()->toRfc4122(), $neuron->getSourceUuid()?->toRfc4122());
    }

    public function testReturnsEmptyWhenLLMReturnsNull(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse(null));

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', ['fact' => 'L\'eau bout à 100°C']);

        $result = $extractor->extract($source, BrainArea::Episodic);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->debug['extracted_count']);
        $this->assertSame('llm_returned_null', $result->debug['reason']);
    }

    public function testLocationCanBeNull(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'occurred_at' => '2026-04-11T14:30:00Z',
            'event_summary' => 'Événement sans lieu',
            'actors' => ['X'],
            'location' => null,
        ]));

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', []);

        $result = $extractor->extract($source, BrainArea::Episodic);

        /** @var EpisodicNeuron $neuron */
        $neuron = $result->neurons[0];
        $this->assertNull($neuron->getLocation());
    }

    public function testActorsAreFilteredToStringsOnly(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'occurred_at' => '2026-04-11T14:30:00Z',
            'event_summary' => 'Test acteurs',
            'actors' => ['Alice', 42, null, 'Bob', ['nested']],
            'location' => null,
        ]));

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', []);

        $result = $extractor->extract($source, BrainArea::Episodic);

        /** @var EpisodicNeuron $neuron */
        $neuron = $result->neurons[0];
        $this->assertSame(['Alice', 'Bob'], $neuron->getActors());
    }

    public function testThrowsWhenWrongAreaRequested(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);

        $extractor->extract($source, BrainArea::Semantic);
    }

    public function testThrowsOnChatServiceFailure(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willThrowException(new \RuntimeException('LLM down'));

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', []);

        try {
            $extractor->extract($source, BrainArea::Episodic);
            $this->fail('Expected ExtractionFailedException');
        } catch (ExtractionFailedException $e) {
            $this->assertStringContainsString('LLM down', $e->getMessage());
        }
    }

    public function testThrowsOnMissingEpisodeKey(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn([
            'answer' => '',
            'debug_id' => null,
            'call_id' => null,
            'usage' => [],
            'safety' => [],
            'model' => 'gemini-test',
            'preset_id' => null,
            'agent_id' => null,
            'generated_attachments' => [],
            'structured_output' => [], // pas de clé 'episode'
        ]);

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/missing "episode"/');

        $extractor->extract($source, BrainArea::Episodic);
    }

    public function testThrowsOnInvalidOccurredAt(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'occurred_at' => 'pas une date',
            'event_summary' => 'Test',
            'actors' => [],
            'location' => null,
        ]));

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/invalid occurred_at/');

        $extractor->extract($source, BrainArea::Episodic);
    }

    public function testThrowsWhenEpisodeIsScalar(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn([
            'answer' => '',
            'debug_id' => null,
            'call_id' => null,
            'usage' => [],
            'safety' => [],
            'model' => 'gemini-test',
            'preset_id' => null,
            'agent_id' => null,
            'generated_attachments' => [],
            'structured_output' => ['episode' => 'pas un objet ni null'],
        ]);

        $extractor = new EpisodicExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/must be object or null/');

        $extractor->extract($source, BrainArea::Episodic);
    }
}
