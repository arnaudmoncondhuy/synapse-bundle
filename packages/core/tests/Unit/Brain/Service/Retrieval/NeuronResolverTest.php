<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\NeuronResolver;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EncyclopedicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EpisodicNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\ProceduralNeuronRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\SemanticNeuronRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class NeuronResolverTest extends TestCase
{
    public function testResolvesSemanticByArea(): void
    {
        $neuron = new SemanticNeuron(firstSource: Uuid::v7(), subject: 'X', predicate: 'is', value: 'Y');

        $semanticRepo = $this->createMock(SemanticNeuronRepository::class);
        $semanticRepo->method('find')->with($neuron->getId())->willReturn($neuron);

        $resolver = new NeuronResolver(
            semanticRepo: $semanticRepo,
            episodicRepo: $this->createStub(EpisodicNeuronRepository::class),
            encyclopedicRepo: $this->createStub(EncyclopedicNeuronRepository::class),
            proceduralRepo: $this->createStub(ProceduralNeuronRepository::class),
        );

        $this->assertSame($neuron, $resolver->resolve(BrainArea::Semantic, $neuron->getId()));
    }

    public function testResolvesEpisodicByArea(): void
    {
        $neuron = new EpisodicNeuron(
            source: new MemorySource('manual', []),
            occurredAt: new \DateTimeImmutable(),
            eventSummary: 'event',
        );

        $episodicRepo = $this->createMock(EpisodicNeuronRepository::class);
        $episodicRepo->method('find')->with($neuron->getId())->willReturn($neuron);

        $resolver = new NeuronResolver(
            semanticRepo: $this->createStub(SemanticNeuronRepository::class),
            episodicRepo: $episodicRepo,
            encyclopedicRepo: $this->createStub(EncyclopedicNeuronRepository::class),
            proceduralRepo: $this->createStub(ProceduralNeuronRepository::class),
        );

        $this->assertSame($neuron, $resolver->resolve(BrainArea::Episodic, $neuron->getId()));
    }

    public function testResolvesEncyclopedicByArea(): void
    {
        $neuron = new EncyclopedicNeuron(
            source: new MemorySource('manual', []),
            documentRef: 'doc:1',
            chunkIndex: 0,
            totalChunks: 1,
            chunkContent: 'content',
        );

        $encyclopedicRepo = $this->createMock(EncyclopedicNeuronRepository::class);
        $encyclopedicRepo->method('find')->with($neuron->getId())->willReturn($neuron);

        $resolver = new NeuronResolver(
            semanticRepo: $this->createStub(SemanticNeuronRepository::class),
            episodicRepo: $this->createStub(EpisodicNeuronRepository::class),
            encyclopedicRepo: $encyclopedicRepo,
            proceduralRepo: $this->createStub(ProceduralNeuronRepository::class),
        );

        $this->assertSame($neuron, $resolver->resolve(BrainArea::Encyclopedic, $neuron->getId()));
    }

    public function testResolvesProceduralByArea(): void
    {
        $neuron = new ProceduralNeuron(
            source: null,
            name: 'reset',
            triggerPattern: ['reset'],
            steps: ['s1'],
        );

        $proceduralRepo = $this->createMock(ProceduralNeuronRepository::class);
        $proceduralRepo->method('find')->with($neuron->getId())->willReturn($neuron);

        $resolver = new NeuronResolver(
            semanticRepo: $this->createStub(SemanticNeuronRepository::class),
            episodicRepo: $this->createStub(EpisodicNeuronRepository::class),
            encyclopedicRepo: $this->createStub(EncyclopedicNeuronRepository::class),
            proceduralRepo: $proceduralRepo,
        );

        $this->assertSame($neuron, $resolver->resolve(BrainArea::Procedural, $neuron->getId()));
    }

    public function testReturnsNullForInactiveAreas(): void
    {
        $resolver = new NeuronResolver(
            semanticRepo: $this->createStub(SemanticNeuronRepository::class),
            episodicRepo: $this->createStub(EpisodicNeuronRepository::class),
            encyclopedicRepo: $this->createStub(EncyclopedicNeuronRepository::class),
            proceduralRepo: $this->createStub(ProceduralNeuronRepository::class),
        );

        $this->assertNull($resolver->resolve(BrainArea::Emotional, Uuid::v7()));
        $this->assertNull($resolver->resolve(BrainArea::Sensory, Uuid::v7()));
        $this->assertNull($resolver->resolve(BrainArea::Motor, Uuid::v7()));
    }

    public function testReturnsNullWhenRepoFindReturnsNull(): void
    {
        $semanticRepo = $this->createMock(SemanticNeuronRepository::class);
        $semanticRepo->method('find')->willReturn(null);

        $resolver = new NeuronResolver(
            semanticRepo: $semanticRepo,
            episodicRepo: $this->createStub(EpisodicNeuronRepository::class),
            encyclopedicRepo: $this->createStub(EncyclopedicNeuronRepository::class),
            proceduralRepo: $this->createStub(ProceduralNeuronRepository::class),
        );

        $this->assertNull($resolver->resolve(BrainArea::Semantic, Uuid::v7()));
    }
}
