<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Retrieval;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\Retrieval\NeuronOwnerResolver;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\MemorySourceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class NeuronOwnerResolverTest extends TestCase
{
    public function testResolveReturnsOwnerFromSource(): void
    {
        $owner = Uuid::v7();
        $source = new MemorySource('manual', [], null, $owner);

        $repo = $this->createMock(MemorySourceRepository::class);
        $repo->method('find')->with($source->getId())->willReturn($source);

        $resolver = new NeuronOwnerResolver($repo);

        $neuron = new SemanticNeuron(
            firstSource: $source->getId(),
            subject: 'X',
            predicate: 'is',
            value: 'Y',
            confidence: 0.8,
        );

        $this->assertEquals($owner, $resolver->resolve($neuron));
    }

    public function testResolveReturnsNullForOpenSource(): void
    {
        $source = new MemorySource('manual', []);
        $repo = $this->createMock(MemorySourceRepository::class);
        $repo->method('find')->willReturn($source);

        $resolver = new NeuronOwnerResolver($repo);

        $neuron = new SemanticNeuron(
            firstSource: $source->getId(),
            subject: 'X',
            predicate: 'is',
            value: 'Y',
            confidence: 0.8,
        );

        $this->assertNull($resolver->resolve($neuron));
    }

    public function testResolveReturnsOrphanSentinelForMissingSource(): void
    {
        // Audit brain-isolation-paranoid 2026-05-14 : si MemorySource introuvable
        // (data corrompue, source supprimée), resolve() doit retourner une sentinelle
        // non-admissible (fail-closed), pas null (fail-open qui exposait le neurone).
        $repo = $this->createMock(MemorySourceRepository::class);
        $repo->method('find')->willReturn(null);

        $resolver = new NeuronOwnerResolver($repo);

        $neuron = new SemanticNeuron(
            firstSource: Uuid::v7(),
            subject: 'X',
            predicate: 'is',
            value: 'Y',
        );

        $orphan = $resolver->resolve($neuron);
        $this->assertNotNull($orphan, 'Neurone orphelin doit retourner sentinelle, pas null');

        // La sentinelle ne doit matcher AUCUN user réel
        $alice = Uuid::v7();
        $bob = Uuid::v7();
        $this->assertFalse($resolver->isAdmissibleForOwner($orphan, $alice));
        $this->assertFalse($resolver->isAdmissibleForOwner($orphan, $bob));
        $this->assertFalse($resolver->isAdmissibleForOwner($orphan, null));
    }

    public function testCorruptedSourceDoesNotLeakCrossUser(): void
    {
        // Scénario E2E du fail-closed : un neurone privé Bob dont la source est
        // supprimée ne doit pas devenir visible pour Alice.
        $repo = $this->createMock(MemorySourceRepository::class);
        $repo->method('find')->willReturn(null);

        $resolver = new NeuronOwnerResolver($repo);

        $bobOrphanNeuron = new SemanticNeuron(
            firstSource: Uuid::v7(),
            subject: 'orphaned',
            predicate: 'is',
            value: 'leak',
        );

        $owner = $resolver->resolve($bobOrphanNeuron);

        $alice = Uuid::v7();
        $this->assertFalse(
            $resolver->isAdmissibleForOwner($owner, $alice),
            'Neurone orphelin ne doit JAMAIS être admissible pour un user nommé',
        );
    }

    public function testCacheAvoidsRepeatedRepoLookups(): void
    {
        $owner = Uuid::v7();
        $source = new MemorySource('manual', [], null, $owner);

        $repo = $this->createMock(MemorySourceRepository::class);
        // EXPECT: une seule fois, malgré 3 appels resolve()
        $repo->expects($this->once())->method('find')->willReturn($source);

        $resolver = new NeuronOwnerResolver($repo);

        $neuron = new SemanticNeuron(
            firstSource: $source->getId(),
            subject: 'X',
            predicate: 'is',
            value: 'Y',
        );

        $resolver->resolve($neuron);
        $resolver->resolve($neuron);
        $resolver->resolve($neuron);
    }

    public function testIsAdmissibleQueryOwnerNullStrict(): void
    {
        $repo = $this->createStub(MemorySourceRepository::class);
        $resolver = new NeuronOwnerResolver($repo);

        $alice = Uuid::v7();

        // queryOwner null → uniquement neurones open
        $this->assertTrue($resolver->isAdmissibleForOwner(null, null));
        $this->assertFalse($resolver->isAdmissibleForOwner($alice, null));
    }

    public function testIsAdmissibleQueryOwnerNamed(): void
    {
        $repo = $this->createStub(MemorySourceRepository::class);
        $resolver = new NeuronOwnerResolver($repo);

        $alice = Uuid::v7();
        $bob = Uuid::v7();

        // queryOwner Alice → admet Alice et open, refuse Bob
        $this->assertTrue($resolver->isAdmissibleForOwner($alice, $alice));
        $this->assertTrue($resolver->isAdmissibleForOwner(null, $alice));
        $this->assertFalse($resolver->isAdmissibleForOwner($bob, $alice));
    }

    public function testResolveNeuronWithoutSourceReturnsNull(): void
    {
        // Neurone procédural écrit à la main (source = null)
        $repo = $this->createStub(MemorySourceRepository::class);
        $resolver = new NeuronOwnerResolver($repo);

        $neuron = new ProceduralNeuron(
            source: null,
            name: 'reset password',
            triggerPattern: ['reset'],
            steps: ['step1'],
        );

        $this->assertNull($resolver->resolve($neuron));
    }
}
