<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseEdgeType;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapsePolarity;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseRelationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

class SynapseTest extends TestCase
{
    private function buildSemantic(): SemanticNeuron
    {
        return new SemanticNeuron(Uuid::v7(), 'X', 'is', 'matin');
    }

    private function buildEpisodic(): EpisodicNeuron
    {
        return new EpisodicNeuron(
            new MemorySource('manual', []),
            new \DateTimeImmutable(),
            'événement',
        );
    }

    public function testConstructorGeneratesUuidV7(): void
    {
        $synapse = new Synapse($this->buildSemantic(), $this->buildEpisodic());

        $this->assertInstanceOf(UuidV7::class, $synapse->getId());
    }

    public function testCopiesAreaAndIdFromBothNeurons(): void
    {
        $a = $this->buildSemantic();
        $b = $this->buildEpisodic();

        $synapse = new Synapse($a, $b);

        $this->assertSame(BrainArea::Semantic, $synapse->getSourceNeuronArea());
        $this->assertSame($a->getId(), $synapse->getSourceNeuronId());
        $this->assertSame(BrainArea::Episodic, $synapse->getTargetNeuronArea());
        $this->assertSame($b->getId(), $synapse->getTargetNeuronId());
    }

    public function testDefaultsMatchDesign(): void
    {
        $synapse = new Synapse($this->buildSemantic(), $this->buildEpisodic());

        $this->assertSame(0.1, $synapse->getWeight());
        $this->assertSame(SynapsePolarity::Excitatory, $synapse->getPolarity());
        $this->assertSame(SynapseRelationType::Generic, $synapse->getRelationType());
        $this->assertSame(0.5, $synapse->getConfidence());
        $this->assertSame(1, $synapse->getEvidenceCount());
        $this->assertNull($synapse->getContextId());
    }

    public function testWeightIsMutable(): void
    {
        $synapse = new Synapse($this->buildSemantic(), $this->buildEpisodic());
        $synapse->setWeight(0.75);

        $this->assertSame(0.75, $synapse->getWeight());
    }

    public function testPolarityIsMutable(): void
    {
        $synapse = new Synapse($this->buildSemantic(), $this->buildEpisodic());
        $synapse->setPolarity(SynapsePolarity::Inhibitory);

        $this->assertSame(SynapsePolarity::Inhibitory, $synapse->getPolarity());
    }

    public function testRelationTypeIsMutable(): void
    {
        $synapse = new Synapse($this->buildSemantic(), $this->buildEpisodic());
        $synapse->setRelationType(SynapseRelationType::Causal);

        $this->assertSame(SynapseRelationType::Causal, $synapse->getRelationType());
    }

    public function testConfidenceIsMutable(): void
    {
        $synapse = new Synapse($this->buildSemantic(), $this->buildEpisodic());
        $synapse->setConfidence(0.9);

        $this->assertSame(0.9, $synapse->getConfidence());
    }

    public function testRecordCorroborationIncrementsAndUpdatesTimestamp(): void
    {
        $synapse = new Synapse($this->buildSemantic(), $this->buildEpisodic());
        $initial = $synapse->getLastActivatedAt();

        usleep(2000);
        $synapse->recordCorroboration();

        $this->assertSame(2, $synapse->getEvidenceCount());
        $this->assertGreaterThan($initial, $synapse->getLastActivatedAt());
    }

    public function testContextIdIsAssignableViaConstructor(): void
    {
        $context = Uuid::v7();
        $synapse = new Synapse(
            $this->buildSemantic(),
            $this->buildEpisodic(),
            contextId: $context,
        );

        $this->assertSame($context, $synapse->getContextId());
    }

    public function testInferEdgeTypeAssociationBetweenAssociationAreas(): void
    {
        $this->assertSame(
            SynapseEdgeType::Association,
            Synapse::inferEdgeType(BrainArea::Episodic, BrainArea::Semantic),
        );
    }

    /**
     * @return iterable<string, array{BrainArea, BrainArea}>
     */
    public static function transductionPairProvider(): iterable
    {
        yield 'sensory→episodic' => [BrainArea::Sensory, BrainArea::Episodic];
        yield 'episodic→motor' => [BrainArea::Episodic, BrainArea::Motor];
        yield 'sensory→motor' => [BrainArea::Sensory, BrainArea::Motor];
        yield 'motor→semantic' => [BrainArea::Motor, BrainArea::Semantic];
    }

    #[DataProvider('transductionPairProvider')]
    public function testInferEdgeTypeTransductionWhenOneSide(BrainArea $a, BrainArea $b): void
    {
        $this->assertSame(SynapseEdgeType::Transduction, Synapse::inferEdgeType($a, $b));
    }

    public function testExplicitEdgeTypeOverridesInference(): void
    {
        $synapse = new Synapse(
            $this->buildSemantic(),
            $this->buildEpisodic(),
            edgeType: SynapseEdgeType::Transduction,
        );

        $this->assertSame(SynapseEdgeType::Transduction, $synapse->getEdgeType());
    }
}
