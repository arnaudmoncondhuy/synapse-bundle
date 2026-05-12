<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Entity;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\SynapseUserIsolationViolationException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SynapseUserIsolationTest extends TestCase
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

    // ── Cas admissibles ─────────────────────────────────────────────────────

    public function testAdmissibleWhenBothOwnersNull(): void
    {
        $synapse = new Synapse(
            source: $this->buildSemantic(),
            target: $this->buildEpisodic(),
            sourceOwnerId: null,
            targetOwnerId: null,
        );

        $this->assertNull($synapse->getSourceNeuronOwner());
        $this->assertNull($synapse->getTargetNeuronOwner());
    }

    public function testAdmissibleWhenSameOwner(): void
    {
        $owner = Uuid::v7();

        $synapse = new Synapse(
            source: $this->buildSemantic(),
            target: $this->buildEpisodic(),
            sourceOwnerId: $owner,
            targetOwnerId: $owner,
        );

        $this->assertSame($owner, $synapse->getSourceNeuronOwner());
        $this->assertSame($owner, $synapse->getTargetNeuronOwner());
    }

    public function testAdmissibleWhenSourcePrivateAndTargetOpen(): void
    {
        $owner = Uuid::v7();

        $synapse = new Synapse(
            source: $this->buildSemantic(),
            target: $this->buildEpisodic(),
            sourceOwnerId: $owner,
            targetOwnerId: null,
        );

        $this->assertSame($owner, $synapse->getSourceNeuronOwner());
        $this->assertNull($synapse->getTargetNeuronOwner());
    }

    public function testAdmissibleWhenSourceOpenAndTargetPrivate(): void
    {
        $owner = Uuid::v7();

        $synapse = new Synapse(
            source: $this->buildSemantic(),
            target: $this->buildEpisodic(),
            sourceOwnerId: null,
            targetOwnerId: $owner,
        );

        $this->assertNull($synapse->getSourceNeuronOwner());
        $this->assertSame($owner, $synapse->getTargetNeuronOwner());
    }

    // ── Cas interdit ────────────────────────────────────────────────────────

    public function testThrowsWhenOwnersAreDifferentAndBothNonNull(): void
    {
        $alice = Uuid::v7();
        $bob = Uuid::v7();

        $this->expectException(SynapseUserIsolationViolationException::class);
        $this->expectExceptionMessageMatches('/different non-null owners/');

        new Synapse(
            source: $this->buildSemantic(),
            target: $this->buildEpisodic(),
            sourceOwnerId: $alice,
            targetOwnerId: $bob,
        );
    }

    public function testExceptionCarriesContextForDebug(): void
    {
        $alice = Uuid::v7();
        $bob = Uuid::v7();

        $source = $this->buildSemantic();
        $target = $this->buildEpisodic();

        try {
            new Synapse(
                source: $source,
                target: $target,
                sourceOwnerId: $alice,
                targetOwnerId: $bob,
            );
            $this->fail('Expected SynapseUserIsolationViolationException');
        } catch (SynapseUserIsolationViolationException $e) {
            $this->assertSame($source->getId(), $e->sourceNeuronId);
            $this->assertSame($target->getId(), $e->targetNeuronId);
            $this->assertSame($alice, $e->sourceOwnerId);
            $this->assertSame($bob, $e->targetOwnerId);
            $this->assertSame($source->getArea(), $e->sourceArea);
            $this->assertSame($target->getArea(), $e->targetArea);
        }
    }

    public function testDefaultsToBothNullWhenOwnersOmitted(): void
    {
        // Backwards compatibility : on peut toujours créer une Synapse sans
        // owners — c'est traité comme "open / open" et c'est admissible.
        $synapse = new Synapse(
            source: $this->buildSemantic(),
            target: $this->buildEpisodic(),
        );

        $this->assertNull($synapse->getSourceNeuronOwner());
        $this->assertNull($synapse->getTargetNeuronOwner());
    }
}
