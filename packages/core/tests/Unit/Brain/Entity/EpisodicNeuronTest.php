<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Entity;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

class EpisodicNeuronTest extends TestCase
{
    public function testImplementsMemoryFragmentContract(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EpisodicNeuron($source, new \DateTimeImmutable(), 'rencontre');

        $this->assertInstanceOf(MemoryFragment::class, $neuron);
    }

    public function testGetAreaReturnsEpisodic(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EpisodicNeuron($source, new \DateTimeImmutable(), 'rencontre');

        $this->assertSame(BrainArea::Episodic, $neuron->getArea());
    }

    public function testGetIdReturnsUuidV7(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EpisodicNeuron($source, new \DateTimeImmutable(), 'test');

        $this->assertInstanceOf(UuidV7::class, $neuron->getId());
    }

    public function testSourceUuidPointsToSourceId(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EpisodicNeuron($source, new \DateTimeImmutable(), 'test');

        $this->assertSame(
            $source->getId()->toRfc4122(),
            $neuron->getSourceUuid()?->toRfc4122(),
        );
    }

    public function testConstructorAssignsAllFields(): void
    {
        $source = new MemorySource('manual', []);
        $occurredAt = new \DateTimeImmutable('2026-05-12T14:30:00+00:00');
        $sequence = Uuid::v7();

        $neuron = new EpisodicNeuron(
            $source,
            $occurredAt,
            'résumé événement',
            ['alice', 'bob'],
            [0.1, 0.2, 0.3],
            'visioconférence',
            $sequence,
        );

        $this->assertSame($occurredAt, $neuron->getOccurredAt());
        $this->assertSame('résumé événement', $neuron->getEventSummary());
        $this->assertSame(['alice', 'bob'], $neuron->getActors());
        $this->assertSame([0.1, 0.2, 0.3], $neuron->getEmbedding());
        $this->assertSame('visioconférence', $neuron->getLocation());
        $this->assertSame($sequence, $neuron->getSequenceId());
    }

    public function testDefaultsAreEmptyOrNull(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EpisodicNeuron($source, new \DateTimeImmutable(), 'test');

        $this->assertSame([], $neuron->getActors());
        $this->assertSame([], $neuron->getEmbedding());
        $this->assertNull($neuron->getLocation());
        $this->assertNull($neuron->getSequenceId());
    }

    public function testEmbeddingIsMutable(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EpisodicNeuron($source, new \DateTimeImmutable(), 'test');

        $neuron->setEmbedding([0.5, 0.6]);

        $this->assertSame([0.5, 0.6], $neuron->getEmbedding());
    }

    public function testSequenceIdIsMutable(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EpisodicNeuron($source, new \DateTimeImmutable(), 'test');
        $sequence = Uuid::v7();

        $neuron->setSequenceId($sequence);

        $this->assertSame($sequence, $neuron->getSequenceId());
    }
}
