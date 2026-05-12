<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Entity;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

class SemanticNeuronTest extends TestCase
{
    public function testImplementsMemoryFragmentContract(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'preference', 'matin');

        $this->assertInstanceOf(MemoryFragment::class, $neuron);
    }

    public function testGetAreaReturnsSemantic(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'preference', 'matin');

        $this->assertSame(BrainArea::Semantic, $neuron->getArea());
    }

    public function testGetIdReturnsUuidV7(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'preference', 'matin');

        $this->assertInstanceOf(UuidV7::class, $neuron->getId());
    }

    public function testInitialEvidenceCountIsOne(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'preference', 'matin');

        $this->assertSame(1, $neuron->getEvidenceCount());
    }

    public function testGetSourceUuidReturnsFirstSource(): void
    {
        $first = Uuid::v7();
        $neuron = new SemanticNeuron($first, 'X', 'preference', 'matin');

        $this->assertSame($first->toRfc4122(), $neuron->getSourceUuid()?->toRfc4122());
    }

    public function testCorroborateAddsNewSource(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $neuron = new SemanticNeuron($first, 'X', 'preference', 'matin');

        $neuron->corroborate($second);

        $this->assertSame(2, $neuron->getEvidenceCount());
        $rfcs = array_map(static fn (Uuid $u): string => $u->toRfc4122(), $neuron->getSourceUuids());
        $this->assertContains($second->toRfc4122(), $rfcs);
    }

    public function testCorroborateIsIdempotent(): void
    {
        $first = Uuid::v7();
        $neuron = new SemanticNeuron($first, 'X', 'preference', 'matin');

        $neuron->corroborate($first);
        $neuron->corroborate($first);

        $this->assertSame(1, $neuron->getEvidenceCount());
    }

    public function testCorroborateUpdatesLastCorroboratedAt(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'preference', 'matin');
        $initial = $neuron->getLastCorroboratedAt();

        usleep(2000);
        $neuron->corroborate(Uuid::v7());

        $this->assertGreaterThan($initial, $neuron->getLastCorroboratedAt());
    }

    public function testFieldsArePersisted(): void
    {
        $neuron = new SemanticNeuron(
            Uuid::v7(),
            'préférence horaire',
            'is',
            'matin',
            0.85,
            [0.1, 0.2, 0.3],
        );

        $this->assertSame('préférence horaire', $neuron->getSubject());
        $this->assertSame('is', $neuron->getPredicate());
        $this->assertSame('matin', $neuron->getValue());
        $this->assertSame(0.85, $neuron->getConfidence());
        $this->assertSame([0.1, 0.2, 0.3], $neuron->getEmbedding());
    }

    public function testDefaultConfidenceIsHalf(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'Y');

        $this->assertSame(0.5, $neuron->getConfidence());
    }

    public function testConfidenceIsMutable(): void
    {
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'Y');
        $neuron->setConfidence(0.9);

        $this->assertSame(0.9, $neuron->getConfidence());
    }
}
