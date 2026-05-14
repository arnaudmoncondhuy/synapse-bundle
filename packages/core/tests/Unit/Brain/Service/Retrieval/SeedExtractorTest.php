<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\NeuronOwnerResolverInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\SeedExtractor;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EncyclopedicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EpisodicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\SemanticNeuronRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SeedExtractorTest extends TestCase
{
    private function buildSemantic(string $subject, array $embedding = []): SemanticNeuron
    {
        $n = new SemanticNeuron(
            firstSource: Uuid::v7(),
            subject: $subject,
            predicate: 'is',
            value: 'value',
        );
        if ([] !== $embedding) {
            $n->setEmbedding($embedding);
        }

        return $n;
    }

    private function mockEmbeddingService(array $queryEmbedding): EmbeddingService
    {
        $svc = $this->createMock(EmbeddingService::class);
        $svc->method('generateEmbeddings')->willReturn([
            'embeddings' => [$queryEmbedding],
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ]);

        return $svc;
    }

    /**
     * Owner resolver permissif (tout admissible). Default `resolve` retourne null.
     */
    private function permissiveOwnerResolver(): NeuronOwnerResolverInterface
    {
        $resolver = $this->createStub(NeuronOwnerResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);
        $resolver->method('isAdmissibleForOwner')->willReturn(true);

        return $resolver;
    }

    private function buildSE(
        EmbeddingService $emb,
        array $semantic = [],
        array $episodic = [],
        array $encyclopedic = [],
        ?NeuronOwnerResolverInterface $ownerResolver = null,
        float $threshold = SeedExtractor::DEFAULT_SEED_THRESHOLD,
        int $maxSeeds = SeedExtractor::DEFAULT_MAX_SEEDS,
    ): SeedExtractor {
        $semanticRepo = $this->createStub(SemanticNeuronRepository::class);
        $semanticRepo->method('findAll')->willReturn($semantic);
        $episodicRepo = $this->createStub(EpisodicNeuronRepository::class);
        $episodicRepo->method('findAll')->willReturn($episodic);
        $encyclopedicRepo = $this->createStub(EncyclopedicNeuronRepository::class);
        $encyclopedicRepo->method('findAll')->willReturn($encyclopedic);

        return new SeedExtractor(
            embeddingService: $emb,
            semanticRepo: $semanticRepo,
            episodicRepo: $episodicRepo,
            encyclopedicRepo: $encyclopedicRepo,
            ownerResolver: $ownerResolver ?? $this->permissiveOwnerResolver(),
            seedThreshold: $threshold,
            maxSeeds: $maxSeeds,
        );
    }

    public function testReturnsEmptyWhenQueryEmbeddingFails(): void
    {
        $emb = $this->createMock(EmbeddingService::class);
        $emb->method('generateEmbeddings')->willReturn(['embeddings' => [[]], 'usage' => []]);

        $se = $this->buildSE($emb);
        $result = $se->extract(new RetrievalQuery('q'));
        $this->assertSame([], $result);
    }

    public function testReturnsEmptyWhenNoCandidates(): void
    {
        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $se = $this->buildSE($emb);

        $result = $se->extract(new RetrievalQuery('q'));
        $this->assertSame([], $result);
    }

    public function testSkipsCandidatesWithoutEmbedding(): void
    {
        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $noEmb = $this->buildSemantic('no-emb'); // pas d'embedding

        $se = $this->buildSE($emb, semantic: [$noEmb]);
        $result = $se->extract(new RetrievalQuery('q'));
        $this->assertSame([], $result);
    }

    public function testReturnsSeedsAboveThreshold(): void
    {
        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $close = $this->buildSemantic('close', [1.0, 0.0]);   // cosine 1.0
        $far = $this->buildSemantic('far', [0.0, 1.0]);       // cosine 0.0

        $se = $this->buildSE($emb, semantic: [$close, $far], threshold: 0.4);
        $result = $se->extract(new RetrievalQuery('q'));

        $this->assertCount(1, $result);
        $this->assertSame($close, $result[0]->neuron);
        $this->assertEqualsWithDelta(1.0, $result[0]->score, 0.001);
    }

    public function testSortsByScoreDescending(): void
    {
        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $n1 = $this->buildSemantic('n1', [0.8, 0.6]);   // cos ~0.8
        $n2 = $this->buildSemantic('n2', [1.0, 0.0]);   // cos = 1.0
        $n3 = $this->buildSemantic('n3', [0.5, 0.866]); // cos = 0.5

        $se = $this->buildSE($emb, semantic: [$n1, $n2, $n3], threshold: 0.4);
        $result = $se->extract(new RetrievalQuery('q'));

        $this->assertCount(3, $result);
        $this->assertSame($n2, $result[0]->neuron);
        $this->assertSame($n1, $result[1]->neuron);
        $this->assertSame($n3, $result[2]->neuron);
    }

    public function testRespectsMaxSeeds(): void
    {
        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $neurons = [];
        for ($i = 0; $i < 10; ++$i) {
            $neurons[] = $this->buildSemantic("n{$i}", [1.0, 0.0]);
        }

        $se = $this->buildSE($emb, semantic: $neurons, threshold: 0.4, maxSeeds: 3);
        $result = $se->extract(new RetrievalQuery('q'));

        $this->assertCount(3, $result);
    }

    public function testFiltersByOwnerCrossUser(): void
    {
        // BLOQUANT fix audit isolation-paranoid : un neurone Bob qui match query Alice
        // ne doit PAS être retourné comme seed pour Alice.
        $alice = Uuid::v7();
        $bob = Uuid::v7();

        $bobNeuron = $this->buildSemantic('bob_neuron', [1.0, 0.0]);

        $ownerResolver = $this->createMock(NeuronOwnerResolverInterface::class);
        $ownerResolver->method('resolve')->willReturn($bob);
        $ownerResolver->method('isAdmissibleForOwner')->willReturnCallback(
            static fn (?Uuid $n, ?Uuid $q): bool => null === $q ? null === $n : null === $n || $n->equals($q),
        );

        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $se = $this->buildSE($emb, semantic: [$bobNeuron], ownerResolver: $ownerResolver);

        $result = $se->extract(new RetrievalQuery('q', ownerId: $alice));

        $this->assertSame([], $result, 'Alice ne doit pas voir le seed Bob');
    }

    public function testAdmitsOpenNeuronsForNamedOwner(): void
    {
        // Open neuron est admissible pour Alice
        $alice = Uuid::v7();

        $openNeuron = $this->buildSemantic('open', [1.0, 0.0]);

        $ownerResolver = $this->createMock(NeuronOwnerResolverInterface::class);
        $ownerResolver->method('resolve')->willReturn(null);
        $ownerResolver->method('isAdmissibleForOwner')->willReturnCallback(
            static fn (?Uuid $n, ?Uuid $q): bool => null === $q ? null === $n : null === $n || $n->equals($q),
        );

        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $se = $this->buildSE($emb, semantic: [$openNeuron], ownerResolver: $ownerResolver);

        $result = $se->extract(new RetrievalQuery('q', ownerId: $alice));
        $this->assertCount(1, $result);
    }

    public function testMergesAcrossThreeAreas(): void
    {
        $emb = $this->mockEmbeddingService([1.0, 0.0]);
        $sem = $this->buildSemantic('sem', [1.0, 0.0]);
        $epi = $this->buildSemantic('epi', [1.0, 0.0]); // even if SemanticNeuron, suffit pour le test
        $enc = $this->buildSemantic('enc', [1.0, 0.0]);

        $se = $this->buildSE($emb, semantic: [$sem], episodic: [$epi], encyclopedic: [$enc], threshold: 0.4);
        $result = $se->extract(new RetrievalQuery('q'));

        $reached = array_map(fn ($s) => $s->neuron, $result);
        $this->assertContains($sem, $reached);
        $this->assertContains($epi, $reached);
        $this->assertContains($enc, $reached);
    }
}
