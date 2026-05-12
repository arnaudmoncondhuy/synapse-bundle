<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\SemanticExtractor;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;

class SemanticExtractorTest extends TestCase
{
    public function testSupportedAreasReturnsSemantic(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $extractor = new SemanticExtractor($chatService);

        $this->assertSame([BrainArea::Semantic], $extractor->supportedAreas());
    }

    public function testExtractsFactsFromStructuredOutput(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn([
            'answer' => '',
            'debug_id' => null,
            'call_id' => null,
            'usage' => ['prompt' => 100, 'completion' => 50],
            'safety' => [],
            'model' => 'gemini-test',
            'preset_id' => null,
            'agent_id' => null,
            'generated_attachments' => [],
            'structured_output' => [
                'facts' => [
                    [
                        'subject' => 'Alice',
                        'predicate' => 'preference_horaire',
                        'value' => 'matin',
                        'confidence' => 0.9,
                    ],
                    [
                        'subject' => 'Alice',
                        'predicate' => 'telephone',
                        'value' => '06 12 34 56 78',
                        'confidence' => 1.0,
                    ],
                ],
            ],
        ]);

        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', ['note' => 'Alice préfère le matin, tel: 06 12 34 56 78']);

        $result = $extractor->extract($source, BrainArea::Semantic);

        $this->assertSame(BrainArea::Semantic, $result->area);
        $this->assertCount(2, $result->neurons);
        $this->assertSame('gemini-test', $result->debug['model']);
        $this->assertSame(2, $result->debug['extracted_count']);

        /** @var SemanticNeuron $first */
        $first = $result->neurons[0];
        $this->assertInstanceOf(SemanticNeuron::class, $first);
        $this->assertSame('Alice', $first->getSubject());
        $this->assertSame('preference_horaire', $first->getPredicate());
        $this->assertSame('matin', $first->getValue());
        $this->assertSame(0.9, $first->getConfidence());
        $this->assertSame($source->getId()->toRfc4122(), $first->getSourceUuid()?->toRfc4122());
    }

    public function testReturnsEmptyResultWhenLLMReturnsNoFacts(): void
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
            'structured_output' => ['facts' => []],
        ]);

        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', ['bruit' => 'pas de fait extractible']);

        $result = $extractor->extract($source, BrainArea::Semantic);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->debug['extracted_count']);
    }

    public function testSkipsInvalidFactsInArray(): void
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
            'structured_output' => [
                'facts' => [
                    ['subject' => 'X', 'predicate' => 'is', 'value' => 'OK', 'confidence' => 0.8],
                    'not_an_array',                                              // ignoré
                    ['subject' => 'Y', 'predicate' => 'is'],                     // value manquant, ignoré
                    ['subject' => 123, 'predicate' => 'is', 'value' => 'bad'],   // subject pas string, ignoré
                ],
            ],
        ]);

        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', []);

        $result = $extractor->extract($source, BrainArea::Semantic);

        // 1 seul fait valide dans la liste de 4 → 1 neurone créé,
        // mais debug.raw_count contient les 4 items reçus
        $this->assertCount(1, $result->neurons);
        $this->assertSame(4, $result->debug['raw_count']);
        $this->assertSame(1, $result->debug['extracted_count']);
    }

    public function testThrowsWhenWrongAreaRequested(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);

        $extractor->extract($source, BrainArea::Episodic);
    }

    public function testThrowsOnChatServiceFailure(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willThrowException(new \RuntimeException('LLM unreachable'));

        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', []);

        try {
            $extractor->extract($source, BrainArea::Semantic);
            $this->fail('Expected ExtractionFailedException');
        } catch (ExtractionFailedException $e) {
            $this->assertStringContainsString('LLM unreachable', $e->getMessage());
            $this->assertSame($source, $e->source);
            $this->assertSame(BrainArea::Semantic, $e->area);
        }
    }

    public function testThrowsWhenStructuredOutputIsAbsent(): void
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
            // structured_output absent → l'abstract détecte au plus haut niveau
        ]);

        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/missing or non-array structured_output/');

        $extractor->extract($source, BrainArea::Semantic);
    }

    public function testThrowsWhenStructuredOutputMissesFactsKey(): void
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
            'structured_output' => ['other_key' => 'no facts'],
        ]);

        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', []);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/missing "facts"/');

        $extractor->extract($source, BrainArea::Semantic);
    }

    public function testUsesDefaultConfidenceWhenLLMOmitsIt(): void
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
            'structured_output' => [
                'facts' => [
                    ['subject' => 'X', 'predicate' => 'is', 'value' => 'Y'],
                ],
            ],
        ]);

        $extractor = new SemanticExtractor($chatService);
        $source = new MemorySource('manual', []);

        $result = $extractor->extract($source, BrainArea::Semantic);

        $this->assertCount(1, $result->neurons);
        /** @var SemanticNeuron $neuron */
        $neuron = $result->neurons[0];
        $this->assertSame(0.5, $neuron->getConfidence());
    }
}
