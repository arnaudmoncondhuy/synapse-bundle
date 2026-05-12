<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\OnePassMultiAreaExtractor;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;

class OnePassMultiAreaExtractorTest extends TestCase
{
    /**
     * @param array<string, mixed> $structuredOutput
     *
     * @return array<string, mixed>
     */
    private function chatServiceResponse(array $structuredOutput): array
    {
        return [
            'answer' => '',
            'debug_id' => null,
            'call_id' => null,
            'usage' => ['prompt' => 100, 'completion' => 50],
            'safety' => [],
            'model' => 'gemini-test',
            'preset_id' => null,
            'agent_id' => null,
            'generated_attachments' => [],
            'structured_output' => $structuredOutput,
        ];
    }

    public function testSupportedAreasReturnsFourActiveAreas(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $extractor = new OnePassMultiAreaExtractor($chatService);

        $this->assertSame(
            [BrainArea::Semantic, BrainArea::Episodic, BrainArea::Encyclopedic, BrainArea::Procedural],
            $extractor->supportedAreas(),
        );
    }

    public function testExtractsAllFourAreasFromCompleteResponse(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'semantic' => [
                'facts' => [
                    ['subject' => 'X', 'predicate' => 'preference', 'value' => 'matin', 'confidence' => 0.9],
                ],
            ],
            'episodic' => [
                'episode' => [
                    'occurred_at' => '2026-04-11T14:30:00Z',
                    'event_summary' => 'Rencontre Alice',
                    'actors' => ['Alice'],
                    'location' => 'conférence',
                ],
            ],
            'encyclopedic' => [
                'chunkable_text' => 'Un long document à indexer.',
            ],
            'procedural' => [
                'procedure' => [
                    'name' => 'send-reminder',
                    'trigger_pattern' => ['event_type' => 'overdue'],
                    'steps' => [['type' => 'send_message']],
                    'conditions' => [],
                ],
            ],
        ]));

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', ['note' => 'multi-area test']);

        $results = $extractor->extractAll($source);

        $this->assertCount(4, $results);

        $byArea = [];
        foreach ($results as $r) {
            $byArea[$r->area->value] = $r;
        }

        // Semantic
        $this->assertCount(1, $byArea['semantic']->neurons);
        $this->assertInstanceOf(SemanticNeuron::class, $byArea['semantic']->neurons[0]);

        // Episodic
        $this->assertCount(1, $byArea['episodic']->neurons);
        $this->assertInstanceOf(EpisodicNeuron::class, $byArea['episodic']->neurons[0]);

        // Encyclopedic — pas de neurones produits (chunking délégué)
        $this->assertCount(0, $byArea['encyclopedic']->neurons);
        $this->assertSame('Un long document à indexer.', $byArea['encyclopedic']->debug['chunkable_text']);

        // Procedural
        $this->assertCount(1, $byArea['procedural']->neurons);
        $this->assertInstanceOf(ProceduralNeuron::class, $byArea['procedural']->neurons[0]);
    }

    public function testExtractsOnlyTheAreasReturned(): void
    {
        // Le LLM ne retourne que sémantique — les autres aires sont vides
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'semantic' => ['facts' => [['subject' => 'X', 'predicate' => 'is', 'value' => 'Y', 'confidence' => 0.8]]],
            'episodic' => ['episode' => null],
            'encyclopedic' => ['chunkable_text' => null],
            'procedural' => ['procedure' => null],
        ]));

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', []);

        $results = $extractor->extractAll($source);

        // 4 résultats retournés (1 par aire), mais seul semantic a des neurones
        $this->assertCount(4, $results);

        $totalNeurons = 0;
        foreach ($results as $r) {
            $totalNeurons += $r->count();
        }
        $this->assertSame(1, $totalNeurons);
    }

    public function testReturnsEmptyArrayWhenLLMReturnsNothingPertinent(): void
    {
        // Toutes les aires retournent vide/null (sélectivité maximale)
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'semantic' => ['facts' => []],
            'episodic' => ['episode' => null],
            'encyclopedic' => ['chunkable_text' => null],
            'procedural' => ['procedure' => null],
        ]));

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', []);

        $results = $extractor->extractAll($source);

        // 4 résultats, tous vides
        $this->assertCount(4, $results);
        foreach ($results as $r) {
            $this->assertTrue($r->isEmpty());
        }
    }

    public function testThrowsOnChatServiceFailure(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willThrowException(new \RuntimeException('LLM down'));

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/multi-area ChatService call failed/');

        $extractor->extractAll($source);
    }

    public function testThrowsOnMissingStructuredOutput(): void
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
            // structured_output absent
        ]);

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/missing or non-array structured_output/');

        $extractor->extractAll($source);
    }

    public function testSkipsInvalidFactsInSemanticArea(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'semantic' => [
                'facts' => [
                    ['subject' => 'X', 'predicate' => 'is', 'value' => 'OK', 'confidence' => 0.8],
                    ['subject' => 123, 'predicate' => 'is', 'value' => 'bad'],
                    'not_an_array',
                ],
            ],
            'episodic' => ['episode' => null],
            'encyclopedic' => ['chunkable_text' => null],
            'procedural' => ['procedure' => null],
        ]));

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', []);

        $results = $extractor->extractAll($source);

        $byArea = [];
        foreach ($results as $r) {
            $byArea[$r->area->value] = $r;
        }

        $this->assertCount(1, $byArea['semantic']->neurons);
        $this->assertSame(3, $byArea['semantic']->debug['raw_count']);
        $this->assertSame(1, $byArea['semantic']->debug['extracted_count']);
    }

    public function testInvalidEpisodeDateProducesNoNeuron(): void
    {
        // occurred_at invalide → on n'arrive pas à parser, on retourne null
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'semantic' => ['facts' => []],
            'episodic' => [
                'episode' => [
                    'occurred_at' => 'pas une date',
                    'event_summary' => 'Test',
                    'actors' => [],
                    'location' => null,
                ],
            ],
            'encyclopedic' => ['chunkable_text' => null],
            'procedural' => ['procedure' => null],
        ]));

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', []);

        $results = $extractor->extractAll($source);

        // Le résultat épisodique n'est pas produit (return null dans builder)
        // donc on a 3 résultats au lieu de 4
        $byArea = [];
        foreach ($results as $r) {
            $byArea[$r->area->value] = $r;
        }

        $this->assertArrayNotHasKey('episodic', $byArea);
    }

    public function testProceduralStepsAreFilteredToObjectsOnly(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn($this->chatServiceResponse([
            'semantic' => ['facts' => []],
            'episodic' => ['episode' => null],
            'encyclopedic' => ['chunkable_text' => null],
            'procedural' => [
                'procedure' => [
                    'name' => 'test',
                    'trigger_pattern' => ['type' => 'manual'],
                    'steps' => [
                        ['type' => 'step1'],
                        'not_an_object',
                        ['type' => 'step2'],
                    ],
                    'conditions' => [],
                ],
            ],
        ]));

        $extractor = new OnePassMultiAreaExtractor($chatService);
        $source = new MemorySource('manual', []);

        $results = $extractor->extractAll($source);

        $byArea = [];
        foreach ($results as $r) {
            $byArea[$r->area->value] = $r;
        }

        /** @var ProceduralNeuron $neuron */
        $neuron = $byArea['procedural']->neurons[0];
        $this->assertCount(2, $neuron->getSteps());
    }
}
