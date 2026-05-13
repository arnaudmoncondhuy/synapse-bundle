<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\HebbianReinforcerInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\MemoryRetriever;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\ScoredNeuron;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\SeedExtractorInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\SpreadingActivationInterface;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\SynapseRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MemoryRetrieverTest extends TestCase
{
    private function buildSemantic(string $subject, array $embedding = []): SemanticNeuron
    {
        $n = new SemanticNeuron(
            firstSource: Uuid::v7(),
            subject: $subject,
            predicate: 'is',
            value: 'value',
            confidence: 0.8,
        );
        if ([] !== $embedding) {
            $n->setEmbedding($embedding);
        }

        return $n;
    }

    private function buildSeed(MemoryFragment $neuron, float $score = 0.8): ScoredNeuron
    {
        return new ScoredNeuron(
            neuron: $neuron,
            score: $score,
            depth: 0,
            reachedVia: null,
        );
    }

    private function buildScored(MemoryFragment $neuron, float $score, int $depth = 1, ?Uuid $reachedVia = null): ScoredNeuron
    {
        return new ScoredNeuron(
            neuron: $neuron,
            score: $score,
            depth: $depth,
            reachedVia: $reachedVia,
        );
    }

    private function mockEmbeddingService(array $embedding = [0.5, 0.5]): EmbeddingService
    {
        $svc = $this->createMock(EmbeddingService::class);
        $svc->method('generateEmbeddings')->willReturn([
            'embeddings' => [$embedding],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]);

        return $svc;
    }

    public function testReturnsEmptyWhenNoSeeds(): void
    {
        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->expects($this->never())->method('spread');

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        $reinforcer->expects($this->never())->method('reinforce');

        $synapseRepo = $this->createStub(SynapseRepository::class);

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $this->mockEmbeddingService(),
        );

        $result = $retriever->retrieve(new RetrievalQuery('rien'));

        $this->assertTrue($result->isEmpty());
        $this->assertSame('no_seeds_matched', $result->debug['reason']);
        $this->assertSame(0, $result->debug['seeds_count']);
    }

    public function testReturnsEmptyWhenSpreadEmpty(): void
    {
        $seed = $this->buildSemantic('X');
        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($seed)]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([]);

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        $reinforcer->expects($this->never())->method('reinforce');

        $synapseRepo = $this->createStub(SynapseRepository::class);

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $this->mockEmbeddingService(),
        );

        $result = $retriever->retrieve(new RetrievalQuery('q'));

        $this->assertTrue($result->isEmpty());
        $this->assertSame('spread_below_min_score', $result->debug['reason']);
    }

    public function testRerankCombinesPathAndCosine(): void
    {
        // Neurone très proche de la query : cosine ≈ 1
        $close = $this->buildSemantic('close', [1.0, 0.0, 0.0]);
        // Neurone éloigné : cosine ≈ 0
        $far = $this->buildSemantic('far', [0.0, 0.0, 1.0]);

        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($close)]);

        // BFS donne au "far" un meilleur score (0.9) qu'à "close" (0.5)
        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([
            $this->buildScored($far, 0.9, 1, Uuid::v7()),
            $this->buildScored($close, 0.5),
        ]);

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);

        $synapseRepo = $this->createStub(SynapseRepository::class);

        // Query embedding parallèle à $close
        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $this->mockEmbeddingService([1.0, 0.0, 0.0]),
        );

        $result = $retriever->retrieve(new RetrievalQuery('q', minScore: 0.0));

        // Le re-rank doit faire remonter "close" :
        // far : 0.6 × 0.9 + 0.4 × 0 = 0.54
        // close : 0.6 × 0.5 + 0.4 × 1.0 = 0.7
        $this->assertSame($close, $result->neurons[0]->neuron);
        $this->assertGreaterThan($result->neurons[1]->score, $result->neurons[0]->score);
        $this->assertEqualsWithDelta(0.7, $result->neurons[0]->score, 0.001);
        $this->assertEqualsWithDelta(0.54, $result->neurons[1]->score, 0.001);
    }

    public function testRerankSkipsWhenNoQueryEmbedding(): void
    {
        $n = $this->buildSemantic('X', [1.0, 0.0]);
        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($n)]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([$this->buildScored($n, 0.7)]);

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        $synapseRepo = $this->createStub(SynapseRepository::class);

        // EmbeddingService renvoie un embedding vide → on saute le re-rank
        $svc = $this->createMock(EmbeddingService::class);
        $svc->method('generateEmbeddings')->willReturn(['embeddings' => [[]], 'usage' => []]);

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $svc,
        );

        $result = $retriever->retrieve(new RetrievalQuery('q', minScore: 0.0));

        // Score inchangé (pas de re-rank appliqué)
        $this->assertEqualsWithDelta(0.7, $result->neurons[0]->score, 0.0001);
        $this->assertFalse($result->debug['query_embedded']);
    }

    public function testRerankKeepsScoreForNeuronWithoutEmbedding(): void
    {
        // Neurone sémantique sans embedding
        $n = $this->buildSemantic('X');

        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($n)]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([$this->buildScored($n, 0.8)]);

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        $synapseRepo = $this->createStub(SynapseRepository::class);

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $this->mockEmbeddingService(),
        );

        $result = $retriever->retrieve(new RetrievalQuery('q', minScore: 0.0));

        $this->assertEqualsWithDelta(0.8, $result->neurons[0]->score, 0.0001);
    }

    public function testRerankSkipsNonEmbeddableNeurons(): void
    {
        $proc = new ProceduralNeuron(
            source: null,
            name: 'reset password',
            triggerPattern: ['reset'],
            steps: ['step1'],
            successRate: 0.9,
        );

        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($proc)]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([$this->buildScored($proc, 0.7)]);

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        $synapseRepo = $this->createStub(SynapseRepository::class);

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $this->mockEmbeddingService(),
        );

        $result = $retriever->retrieve(new RetrievalQuery('q', minScore: 0.0));

        // Procedural n'est pas EmbeddableNeuron, score inchangé
        $this->assertEqualsWithDelta(0.7, $result->neurons[0]->score, 0.0001);
    }

    public function testReinforcesTraversedSynapsesOnly(): void
    {
        // Seed (depth=0, reachedVia=null) ne déclenche PAS reinforce
        // Neuron with depth>0 et reachedVia non-null DÉCLENCHE reinforce
        $seedNeuron = $this->buildSemantic('seed', [1.0, 0.0]);
        $hop1 = $this->buildSemantic('hop1', [0.9, 0.1]);
        $hop2 = $this->buildSemantic('hop2', [0.8, 0.2]);

        $synapse1 = $this->buildSynapse($seedNeuron, $hop1);
        $synapse2 = $this->buildSynapse($hop1, $hop2);

        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($seedNeuron)]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([
            $this->buildScored($seedNeuron, 1.0, 0, null),
            $this->buildScored($hop1, 0.7, 1, $synapse1->getId()),
            $this->buildScored($hop2, 0.5, 2, $synapse2->getId()),
        ]);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('find')->willReturnCallback(static function (Uuid $id) use ($synapse1, $synapse2) {
            return match ($id->toRfc4122()) {
                $synapse1->getId()->toRfc4122() => $synapse1,
                $synapse2->getId()->toRfc4122() => $synapse2,
                default => null,
            };
        });

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        // Exactement 2 reinforce (pas 3 : le seed n'est pas reinforcé)
        $reinforcer->expects($this->exactly(2))
            ->method('reinforce')
            ->with($this->isInstanceOf(Synapse::class), 'retrieval_co_activation');

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $this->mockEmbeddingService(),
        );

        $result = $retriever->retrieve(new RetrievalQuery('q', minScore: 0.0));

        $this->assertSame(2, $result->debug['reinforced_synapses_count']);
    }

    public function testDebugContainsTimingAndCounts(): void
    {
        $n = $this->buildSemantic('X', [1.0, 0.0]);
        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($n, 0.85)]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([$this->buildScored($n, 0.85)]);

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        $synapseRepo = $this->createStub(SynapseRepository::class);

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $this->mockEmbeddingService(),
        );

        $result = $retriever->retrieve(new RetrievalQuery('q', minScore: 0.0));

        $this->assertSame(1, $result->debug['seeds_count']);
        $this->assertEqualsWithDelta(0.85, $result->debug['seed_top_score'], 0.001);
        $this->assertSame(1, $result->debug['spread_count']);
        $this->assertSame(1, $result->debug['reranked_count']);
        $this->assertArrayHasKey('timing_ms', $result->debug);
        $this->assertArrayHasKey('total', $result->debug['timing_ms']);
        $this->assertArrayHasKey('seeds', $result->debug['timing_ms']);
        $this->assertArrayHasKey('spread', $result->debug['timing_ms']);
        $this->assertArrayHasKey('rerank', $result->debug['timing_ms']);
        $this->assertArrayHasKey('reinforce', $result->debug['timing_ms']);
    }

    public function testEmbeddingFailureDoesNotBreakRetrieval(): void
    {
        $n = $this->buildSemantic('X', [1.0, 0.0]);

        $seedExtractor = $this->createMock(SeedExtractorInterface::class);
        $seedExtractor->method('extract')->willReturn([$this->buildSeed($n)]);

        $spread = $this->createMock(SpreadingActivationInterface::class);
        $spread->method('spread')->willReturn([$this->buildScored($n, 0.7)]);

        $reinforcer = $this->createMock(HebbianReinforcerInterface::class);
        $synapseRepo = $this->createStub(SynapseRepository::class);

        // EmbeddingService throw au moment du re-rank
        $svc = $this->createMock(EmbeddingService::class);
        $svc->method('generateEmbeddings')->willThrowException(new \RuntimeException('API down'));

        $retriever = new MemoryRetriever(
            $seedExtractor,
            $spread,
            $reinforcer,
            $synapseRepo,
            $svc,
        );

        $result = $retriever->retrieve(new RetrievalQuery('q', minScore: 0.0));

        // Pas de crash, on a quand même le score BFS
        $this->assertCount(1, $result->neurons);
        $this->assertEqualsWithDelta(0.7, $result->neurons[0]->score, 0.0001);
        $this->assertFalse($result->debug['query_embedded']);
    }

    private function buildSynapse(MemoryFragment $source, MemoryFragment $target): Synapse
    {
        return new Synapse(
            source: $source,
            target: $target,
            weight: 0.5,
            confidence: 0.8,
        );
    }
}
