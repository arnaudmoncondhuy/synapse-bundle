<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Event\SynapseReinforcedEvent;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\HebbianReinforcer;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

class HebbianReinforcerTest extends TestCase
{
    private function buildSynapse(float $weight = 0.5): Synapse
    {
        $semantic = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'Y');
        $episodic = new EpisodicNeuron(new MemorySource('manual', []), new \DateTimeImmutable(), 'évt');

        return new Synapse(
            source: $semantic,
            target: $episodic,
            weight: $weight,
        );
    }

    public function testReinforcementAppliesSoftSaturation(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $reinforcer = new HebbianReinforcer($dispatcher, delta: 0.1);

        $synapse = $this->buildSynapse(0.5);
        $reinforcer->reinforce($synapse);

        // 0.5 + 0.1 × (1 - 0.5) = 0.55
        $this->assertEqualsWithDelta(0.55, $synapse->getWeight(), 0.0001);
    }

    public function testSoftSaturationSlowsDownNearOne(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $reinforcer = new HebbianReinforcer($dispatcher, delta: 0.1);

        $synapse = $this->buildSynapse(0.95);
        $reinforcer->reinforce($synapse);

        // 0.95 + 0.1 × (1 - 0.95) = 0.955 (presque rien gagné)
        $this->assertEqualsWithDelta(0.955, $synapse->getWeight(), 0.0001);
    }

    public function testWeightStaysInBounds(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $reinforcer = new HebbianReinforcer($dispatcher);

        $synapse = $this->buildSynapse(0.5);
        for ($i = 0; $i < 500; ++$i) {
            $reinforcer->reinforce($synapse);
        }

        $this->assertLessThanOrEqual(1.0, $synapse->getWeight());
        $this->assertGreaterThanOrEqual(0.0, $synapse->getWeight());
    }

    public function testDispatchesEventWithOldAndNewWeight(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (object $event): bool {
                if (!$event instanceof SynapseReinforcedEvent) {
                    return false;
                }

                return 0.5 === $event->oldWeight
                    && abs($event->newWeight - 0.55) < 0.0001
                    && 'hebbian_co_activation' === $event->cause;
            }));

        $reinforcer = new HebbianReinforcer($dispatcher, delta: 0.1);
        $synapse = $this->buildSynapse(0.5);

        $reinforcer->reinforce($synapse);
    }

    public function testEventCarriesCustomCause(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $e): bool => $e instanceof SynapseReinforcedEvent && 'manual_admin_boost' === $e->cause));

        $reinforcer = new HebbianReinforcer($dispatcher, delta: 0.1);
        $synapse = $this->buildSynapse(0.5);

        $reinforcer->reinforce($synapse, 'manual_admin_boost');
    }

    public function testNoEventWhenWeightAlreadyAtMax(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $reinforcer = new HebbianReinforcer($dispatcher);
        $synapse = $this->buildSynapse(1.0);

        $reinforcer->reinforce($synapse);

        $this->assertSame(1.0, $synapse->getWeight());
    }

    public function testEvidenceCountIncrementsOnReinforcement(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $reinforcer = new HebbianReinforcer($dispatcher);

        $synapse = $this->buildSynapse(0.5);
        $before = $synapse->getEvidenceCount();
        $reinforcer->reinforce($synapse);

        $this->assertSame($before + 1, $synapse->getEvidenceCount());
    }

    public function testReinforceAllProcessesEachSynapse(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->exactly(3))->method('dispatch');

        $reinforcer = new HebbianReinforcer($dispatcher);
        $synapses = [
            $this->buildSynapse(0.5),
            $this->buildSynapse(0.6),
            $this->buildSynapse(0.7),
        ];

        $reinforcer->reinforceAll($synapses);
    }

    public function testSkipsTransductionSynapse(): void
    {
        // ADR-012 : les synapses Transduction (câblage fixe métier) ne sont JAMAIS renforcées.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $reinforcer = new HebbianReinforcer($dispatcher, delta: 0.1);

        $semantic = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'Y');
        $episodic = new EpisodicNeuron(new MemorySource('manual', []), new \DateTimeImmutable(), 'évt');

        // Création explicite avec edgeType = Transduction (lien structurel métier note→deal)
        $synapse = new Synapse(
            source: $semantic,
            target: $episodic,
            weight: 1.0,
            edgeType: \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseEdgeType::Transduction,
        );

        $initialWeight = $synapse->getWeight();
        $initialEvidence = $synapse->getEvidenceCount();

        $reinforcer->reinforce($synapse);

        // Poids et evidenceCount strictement inchangés (câblage fixe)
        $this->assertSame($initialWeight, $synapse->getWeight());
        $this->assertSame($initialEvidence, $synapse->getEvidenceCount());
    }

    public function testSkipsTransductionEvenWithLowWeight(): void
    {
        // Même les synapses Transduction avec weight bas ne sont pas renforcées :
        // le poids initial reflète la vérité métier, pas un historique d'activation.
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $reinforcer = new HebbianReinforcer($dispatcher);

        $semantic = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'Y');
        $episodic = new EpisodicNeuron(new MemorySource('manual', []), new \DateTimeImmutable(), 'évt');

        $synapse = new Synapse(
            source: $semantic,
            target: $episodic,
            weight: 0.3,
            edgeType: \ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseEdgeType::Transduction,
        );

        $reinforcer->reinforce($synapse);

        $this->assertSame(0.3, $synapse->getWeight());
    }

    public function testGrowthCurveMatchesADR009Numbers(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $reinforcer = new HebbianReinforcer($dispatcher, delta: 0.05);

        $synapse = $this->buildSynapse(0.5);

        // Cf. ADR-009 tableau de comportement (δ = 0.05)
        // Itération 1 : 0.525
        $reinforcer->reinforce($synapse);
        $this->assertEqualsWithDelta(0.525, $synapse->getWeight(), 0.001);

        // Itération 10 cumulées : ~0.701
        for ($i = 0; $i < 9; ++$i) {
            $reinforcer->reinforce($synapse);
        }
        $this->assertEqualsWithDelta(0.701, $synapse->getWeight(), 0.01);
    }
}
