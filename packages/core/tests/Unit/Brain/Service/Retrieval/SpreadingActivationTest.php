<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\NeuronResolverInterface;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\RetrievalQuery;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\ScoredNeuron;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\SpreadingActivation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\SynapseRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

class SpreadingActivationTest extends TestCase
{
    private function buildSemantic(string $subject, ?Uuid $ownerSource = null): SemanticNeuron
    {
        return new SemanticNeuron(
            firstSource: $ownerSource ?? Uuid::v7(),
            subject: $subject,
            predicate: 'is',
            value: 'value',
            confidence: 0.8,
        );
    }

    private function buildEpisodic(string $event): EpisodicNeuron
    {
        return new EpisodicNeuron(
            source: new MemorySource('manual', []),
            occurredAt: new \DateTimeImmutable(),
            eventSummary: $event,
        );
    }

    private function buildSynapse(
        MemoryFragment $source,
        MemoryFragment $target,
        float $weight = 0.8,
        float $confidence = 0.9,
        ?Uuid $sourceOwner = null,
        ?Uuid $targetOwner = null,
    ): Synapse {
        return new Synapse(
            source: $source,
            target: $target,
            sourceOwnerId: $sourceOwner,
            targetOwnerId: $targetOwner,
            weight: $weight,
            confidence: $confidence,
        );
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

    public function testReturnsEmptyForEmptySeeds(): void
    {
        $synapseRepo = $this->createStub(SynapseRepository::class);
        $resolver = $this->createStub(NeuronResolverInterface::class);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([], new RetrievalQuery('q'));

        $this->assertSame([], $result);
    }

    public function testReturnsSingleSeedIfNoOutgoing(): void
    {
        $seed = $this->buildSemantic('X');
        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturn([]);
        $resolver = $this->createStub(NeuronResolverInterface::class);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($seed, 0.9)], new RetrievalQuery('q'));

        $this->assertCount(1, $result);
        $this->assertSame($seed, $result[0]->neuron);
        // Score = arrivingScore × hubFactor(0) = 0.9 × 1.0 = 0.9
        $this->assertEqualsWithDelta(0.9, $result[0]->score, 0.0001);
        $this->assertSame(0, $result[0]->depth);
    }

    public function testPropagatesToDepthOne(): void
    {
        $seed = $this->buildSemantic('X');
        $neighbor = $this->buildSemantic('Y');
        $synapse = $this->buildSynapse($seed, $neighbor, weight: 1.0, confidence: 1.0);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $seed ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn($neighbor);

        $clock = new MockClock();
        $sa = new SpreadingActivation($synapseRepo, $resolver, clock: $clock);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q'));

        $this->assertCount(2, $result);
        // Seed score (sans hub car degree=1) = 1.0
        // Neighbor : propagated = 1.0 × 1.0 × 1.0 × 0.7^1 × recency(≈1) = 0.7
        //          puis × hubFactor(degree=0) = 0.7
        $scores = array_map(fn (ScoredNeuron $s) => $s->score, $result);
        sort($scores);
        $this->assertEqualsWithDelta(0.7, $scores[0], 0.001);
        $this->assertEqualsWithDelta(1.0, $scores[1], 0.001);
    }

    public function testStopsAtMinScore(): void
    {
        $seed = $this->buildSemantic('X');
        $weak = $this->buildSemantic('Y');
        // weight×confidence×decay = 0.1×0.1×0.7 ≈ 0.007 < minScore 0.1
        $synapse = $this->buildSynapse($seed, $weak, weight: 0.1, confidence: 0.1);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $seed ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn($weak);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q'));

        // Seul le seed reste — le voisin est sous minScore
        $this->assertCount(1, $result);
        $this->assertSame($seed, $result[0]->neuron);
    }

    public function testRespectsHardCapDepth(): void
    {
        // Chaîne : A -> B -> C -> D -> E
        // Avec query.maxDepth très haut, HARD_MAX_DEPTH=3 doit limiter à E exclus
        $a = $this->buildSemantic('A');
        $b = $this->buildSemantic('B');
        $c = $this->buildSemantic('C');
        $d = $this->buildSemantic('D');
        $e = $this->buildSemantic('E');

        $synAB = $this->buildSynapse($a, $b, weight: 1.0, confidence: 1.0);
        $synBC = $this->buildSynapse($b, $c, weight: 1.0, confidence: 1.0);
        $synCD = $this->buildSynapse($c, $d, weight: 1.0, confidence: 1.0);
        $synDE = $this->buildSynapse($d, $e, weight: 1.0, confidence: 1.0);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(static function (MemoryFragment $n) use ($a, $b, $c, $d, $synAB, $synBC, $synCD, $synDE) {
            return match (true) {
                $n === $a => [$synAB],
                $n === $b => [$synBC],
                $n === $c => [$synCD],
                $n === $d => [$synDE],
                default => [],
            };
        });

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (BrainArea $area, Uuid $id) use ($b, $c, $d, $e) {
            return match ($id->toRfc4122()) {
                $b->getId()->toRfc4122() => $b,
                $c->getId()->toRfc4122() => $c,
                $d->getId()->toRfc4122() => $d,
                $e->getId()->toRfc4122() => $e,
                default => null,
            };
        });

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        // Force maxDepth très grand pour qu'on teste vraiment le HARD_MAX_DEPTH
        $query = new RetrievalQuery('q', maxDepth: 99, topN: 100, minScore: 0.0);
        $result = $sa->spread([$this->buildSeed($a, 1.0)], $query);

        // HARD_MAX_DEPTH=3 : on peut atteindre depth 3 (= D), mais pas depth 4 (= E)
        $reached = array_map(fn (ScoredNeuron $s) => $s->neuron, $result);
        $this->assertContains($a, $reached);
        $this->assertContains($b, $reached);
        $this->assertContains($c, $reached);
        $this->assertContains($d, $reached);
        $this->assertNotContains($e, $reached);
    }

    public function testHubFactorPenalizesHubs(): void
    {
        $seed = $this->buildSemantic('seed');
        $hub = $this->buildSemantic('hub');
        $synSeedToHub = $this->buildSynapse($seed, $hub, weight: 1.0, confidence: 1.0);

        // Le hub a 50 synapses sortantes (vers des dummies non importants)
        $dummies = [];
        $hubOutSynapses = [$synSeedToHub]; // pas utilisé pour le hub
        $hubOutgoing = [];
        for ($i = 0; $i < 50; ++$i) {
            $d = $this->buildSemantic("d{$i}");
            $dummies[] = $d;
            $hubOutgoing[] = $this->buildSynapse($hub, $d, weight: 0.1, confidence: 0.1);
        }

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(static function (MemoryFragment $n) use ($seed, $hub, $synSeedToHub, $hubOutgoing) {
            return match (true) {
                $n === $seed => [$synSeedToHub],
                $n === $hub => $hubOutgoing,
                default => [],
            };
        });

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (BrainArea $area, Uuid $id) use ($hub, $dummies) {
            if ($id->toRfc4122() === $hub->getId()->toRfc4122()) {
                return $hub;
            }
            foreach ($dummies as $d) {
                if ($id->toRfc4122() === $d->getId()->toRfc4122()) {
                    return $d;
                }
            }

            return null;
        });

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q', minScore: 0.0));

        // Trouve le hub dans les résultats
        $hubResult = null;
        foreach ($result as $r) {
            if ($r->neuron === $hub) {
                $hubResult = $r;
                break;
            }
        }
        $this->assertNotNull($hubResult);

        // hubFactor(50) = 1/(1 + 0.001 × 49²) = 1/(1 + 2.401) ≈ 0.294
        // arriving au hub = 1.0 × 1.0 × 1.0 × 0.7^1 × recency ≈ 0.7
        // finalScore = 0.7 × 0.294 ≈ 0.206
        $this->assertLessThan(0.3, $hubResult->score, 'Hub score should be significantly penalized');
        $this->assertGreaterThan(0.15, $hubResult->score, 'Hub score should still be positive');
    }

    public function testRecencyDecaysOldSynapses(): void
    {
        $seed = $this->buildSemantic('seed');
        $neighbor = $this->buildSemantic('neighbor');
        $synapse = $this->buildSynapse($seed, $neighbor, weight: 1.0, confidence: 1.0);

        // Force la dernière activation à il y a 365 jours via reflection
        $reflect = new \ReflectionProperty(Synapse::class, 'lastActivatedAt');
        $reflect->setValue($synapse, new \DateTimeImmutable('-365 days'));

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $seed ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn($neighbor);

        // Clock à maintenant
        $clock = new MockClock();
        $sa = new SpreadingActivation($synapseRepo, $resolver, clock: $clock);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q', minScore: 0.0));

        // Voisin présent
        $neighborResult = null;
        foreach ($result as $r) {
            if ($r->neuron === $neighbor) {
                $neighborResult = $r;
                break;
            }
        }
        $this->assertNotNull($neighborResult);

        // 0.995^365 ≈ 0.16
        // propagated = 1.0 × 1.0 × 1.0 × 0.7 × 0.16 ≈ 0.112
        $this->assertLessThan(0.15, $neighborResult->score, 'Old synapse should be heavily decayed');
        $this->assertGreaterThan(0.05, $neighborResult->score);
    }

    public function testFanoutCapLimitsExpansion(): void
    {
        $seed = $this->buildSemantic('seed');

        // 30 voisins, mais MAX_FANOUT_PER_HOP = 20
        $neighbors = [];
        $outgoing = [];
        for ($i = 0; $i < 30; ++$i) {
            $n = $this->buildSemantic("n{$i}");
            $neighbors[] = $n;
            // weight décroissant : n0 = 1.0, n29 = 0.1 (les premiers ont les meilleurs scores)
            $w = 1.0 - ($i * 0.03);
            $outgoing[] = $this->buildSynapse($seed, $n, weight: $w, confidence: 1.0);
        }

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(static function (MemoryFragment $n) use ($seed, $outgoing) {
            return $n === $seed ? $outgoing : [];
        });

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (BrainArea $area, Uuid $id) use ($neighbors) {
            foreach ($neighbors as $n) {
                if ($id->toRfc4122() === $n->getId()->toRfc4122()) {
                    return $n;
                }
            }

            return null;
        });

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q', topN: 50, minScore: 0.0));

        // Seed + au plus 20 voisins (les meilleurs)
        $this->assertLessThanOrEqual(21, count($result));
    }

    public function testIsolationUserStrict(): void
    {
        // Synapse Bob → Bob ne doit pas être traversée par Alice
        $alice = Uuid::v7();
        $bob = Uuid::v7();

        $seed = $this->buildSemantic('seed', $bob);
        $neighbor = $this->buildSemantic('bob_neighbor', $bob);
        $synapse = $this->buildSynapse($seed, $neighbor, sourceOwner: $bob, targetOwner: $bob);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $seed ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn($neighbor);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread(
            [$this->buildSeed($seed, 1.0)],
            new RetrievalQuery('q', ownerId: $alice, minScore: 0.0),
        );

        // Alice voit le seed (qu'elle a passé) mais ne peut pas traverser la synapse Bob→Bob
        $this->assertCount(1, $result);
        $this->assertSame($seed, $result[0]->neuron);
    }

    public function testIsolationOpenAllowed(): void
    {
        // Synapse Alice → open est traversée par Alice
        $alice = Uuid::v7();

        $seed = $this->buildSemantic('seed', $alice);
        $openNeighbor = $this->buildSemantic('open');
        $synapse = $this->buildSynapse($seed, $openNeighbor, sourceOwner: $alice, targetOwner: null);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $seed ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn($openNeighbor);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread(
            [$this->buildSeed($seed, 1.0)],
            new RetrievalQuery('q', ownerId: $alice, minScore: 0.0),
        );

        $this->assertCount(2, $result);
    }

    public function testIsolationOpenQueryStrict(): void
    {
        // Query anonyme (ownerId null) → uniquement synapses 100% open
        $alice = Uuid::v7();

        $seed = $this->buildSemantic('seed', $alice);
        $neighbor = $this->buildSemantic('alice_neighbor', $alice);
        $synapse = $this->buildSynapse($seed, $neighbor, sourceOwner: $alice, targetOwner: $alice);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $seed ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn($neighbor);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread(
            [$this->buildSeed($seed, 1.0)],
            new RetrievalQuery('q', ownerId: null, minScore: 0.0),
        );

        // Query anonyme ne traverse pas une synapse Alice→Alice
        $this->assertCount(1, $result);
        $this->assertSame($seed, $result[0]->neuron);
    }

    public function testCycleDoesNotInfiniteLoop(): void
    {
        // Cycle A -> B -> A
        $a = $this->buildSemantic('A');
        $b = $this->buildSemantic('B');

        $synAB = $this->buildSynapse($a, $b, weight: 1.0, confidence: 1.0);
        $synBA = $this->buildSynapse($b, $a, weight: 1.0, confidence: 1.0);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(static function (MemoryFragment $n) use ($a, $b, $synAB, $synBA) {
            return match (true) {
                $n === $a => [$synAB],
                $n === $b => [$synBA],
                default => [],
            };
        });

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (BrainArea $area, Uuid $id) use ($a, $b) {
            return match ($id->toRfc4122()) {
                $a->getId()->toRfc4122() => $a,
                $b->getId()->toRfc4122() => $b,
                default => null,
            };
        });

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($a, 1.0)], new RetrievalQuery('q', minScore: 0.0));

        // A et B chacun visités une fois
        $this->assertCount(2, $result);
        $neuronsReached = array_map(fn (ScoredNeuron $s) => $s->neuron, $result);
        $this->assertContains($a, $neuronsReached);
        $this->assertContains($b, $neuronsReached);
    }

    public function testTopNApplied(): void
    {
        $seed = $this->buildSemantic('seed');
        $neighbors = [];
        $outgoing = [];
        for ($i = 0; $i < 10; ++$i) {
            $n = $this->buildSemantic("n{$i}");
            $neighbors[] = $n;
            $outgoing[] = $this->buildSynapse($seed, $n, weight: 1.0, confidence: 1.0);
        }

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(static function (MemoryFragment $n) use ($seed, $outgoing) {
            return $n === $seed ? $outgoing : [];
        });

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (BrainArea $area, Uuid $id) use ($neighbors) {
            foreach ($neighbors as $n) {
                if ($id->toRfc4122() === $n->getId()->toRfc4122()) {
                    return $n;
                }
            }

            return null;
        });

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q', topN: 3, minScore: 0.0));

        $this->assertCount(3, $result);
    }

    public function testSortedByScoreDesc(): void
    {
        $seed = $this->buildSemantic('seed');
        $high = $this->buildSemantic('high');
        $low = $this->buildSemantic('low');

        $synHigh = $this->buildSynapse($seed, $high, weight: 0.9, confidence: 0.9);
        $synLow = $this->buildSynapse($seed, $low, weight: 0.3, confidence: 0.3);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(static function (MemoryFragment $n) use ($seed, $synHigh, $synLow) {
            return $n === $seed ? [$synHigh, $synLow] : [];
        });

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static function (BrainArea $area, Uuid $id) use ($high, $low) {
            return match ($id->toRfc4122()) {
                $high->getId()->toRfc4122() => $high,
                $low->getId()->toRfc4122() => $low,
                default => null,
            };
        });

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q', minScore: 0.0));

        // Vérifier tri descendant
        for ($i = 1; $i < count($result); ++$i) {
            $this->assertGreaterThanOrEqual($result[$i]->score, $result[$i - 1]->score);
        }
    }

    public function testSkipsWhenTargetUnresolvable(): void
    {
        $seed = $this->buildSemantic('seed');
        $orphan = $this->buildSemantic('orphan');
        $synapse = $this->buildSynapse($seed, $orphan);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $seed ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn(null);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($seed, 1.0)], new RetrievalQuery('q', minScore: 0.0));

        // Seul le seed (l'orphelin ne se résout pas)
        $this->assertCount(1, $result);
        $this->assertSame($seed, $result[0]->neuron);
    }

    public function testMultipleSeeds(): void
    {
        $a = $this->buildSemantic('A');
        $b = $this->buildSemantic('B');

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturn([]);
        $resolver = $this->createStub(NeuronResolverInterface::class);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread(
            [
                $this->buildSeed($a, 0.9),
                $this->buildSeed($b, 0.7),
            ],
            new RetrievalQuery('q'),
        );

        $this->assertCount(2, $result);
        // Tri par score : A (0.9) avant B (0.7)
        $this->assertSame($a, $result[0]->neuron);
        $this->assertSame($b, $result[1]->neuron);
    }

    public function testCrossAreaTraversal(): void
    {
        // Sémantique → Épisodique fonctionne
        $semantic = $this->buildSemantic('seed');
        $episodic = $this->buildEpisodic('event');

        $synapse = $this->buildSynapse($semantic, $episodic, weight: 1.0, confidence: 1.0);

        $synapseRepo = $this->createMock(SynapseRepository::class);
        $synapseRepo->method('findOutgoing')->willReturnCallback(
            fn (MemoryFragment $n) => $n === $semantic ? [$synapse] : []
        );

        $resolver = $this->createMock(NeuronResolverInterface::class);
        $resolver->method('resolve')->willReturn($episodic);

        $sa = new SpreadingActivation($synapseRepo, $resolver);

        $result = $sa->spread([$this->buildSeed($semantic, 1.0)], new RetrievalQuery('q', minScore: 0.0));

        $this->assertCount(2, $result);
        $reached = array_map(fn (ScoredNeuron $s) => $s->neuron, $result);
        $this->assertContains($semantic, $reached);
        $this->assertContains($episodic, $reached);
    }
}
