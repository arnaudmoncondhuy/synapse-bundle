<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service;

use ArnaudMoncondhuy\SynapseCore\Brain\Service\MemoryFragmentSerializer;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\SemanticNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MemoryFragmentSerializerTest extends TestCase
{
    public function testSerializesCommonFields(): void
    {
        $serializer = new MemoryFragmentSerializer();
        $neuron = new SemanticNeuron(Uuid::v7(), 'X', 'is', 'matin');

        $payload = $serializer->serialize($neuron);

        $this->assertSame(SemanticNeuron::class, $payload['class']);
        $this->assertSame($neuron->getId()->toRfc4122(), $payload['id']);
        $this->assertSame(BrainArea::Semantic->value, $payload['area']);
        $this->assertSame($neuron->getSourceUuid()?->toRfc4122(), $payload['sourceUuid']);
    }

    public function testSerializesSemanticNeuronSpecificFields(): void
    {
        $serializer = new MemoryFragmentSerializer();
        $neuron = new SemanticNeuron(Uuid::v7(), 'Alice', 'preference_horaire', 'matin', 0.9);

        $payload = $serializer->serialize($neuron);

        $this->assertSame('Alice', $payload['subject']);
        $this->assertSame('preference_horaire', $payload['predicate']);
        $this->assertSame('matin', $payload['value']);
        $this->assertSame(0.9, $payload['confidence']);
        $this->assertSame(1, $payload['evidenceCount']);
        $this->assertArrayHasKey('lastCorroboratedAt', $payload);
    }

    public function testSerializesEpisodicNeuronSpecificFields(): void
    {
        $serializer = new MemoryFragmentSerializer();
        $source = new MemorySource('manual', []);
        $occurredAt = new \DateTimeImmutable('2026-04-11T14:30:00Z');
        $neuron = new EpisodicNeuron(
            source: $source,
            occurredAt: $occurredAt,
            eventSummary: 'Rencontre',
            actors: ['Alice', 'Bob'],
            location: 'conférence',
        );

        $payload = $serializer->serialize($neuron);

        $this->assertSame('2026-04-11T14:30:00+00:00', $payload['occurredAt']);
        $this->assertSame('conférence', $payload['location']);
        $this->assertSame(['Alice', 'Bob'], $payload['actors']);
        $this->assertSame('Rencontre', $payload['eventSummary']);
        $this->assertNull($payload['sequenceId']);
    }

    public function testSerializesEncyclopedicNeuronSpecificFields(): void
    {
        $serializer = new MemoryFragmentSerializer();
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron(
            source: $source,
            documentRef: 'doc-42',
            chunkIndex: 3,
            totalChunks: 10,
            chunkContent: 'contenu du chunk',
            embedding: [0.1, 0.2],
            docMetadata: ['filename' => 'foo.txt'],
        );

        $payload = $serializer->serialize($neuron);

        $this->assertSame('doc-42', $payload['documentRef']);
        $this->assertSame(3, $payload['chunkIndex']);
        $this->assertSame(10, $payload['totalChunks']);
        $this->assertSame('contenu du chunk', $payload['chunkContent']);
        $this->assertSame(['filename' => 'foo.txt'], $payload['docMetadata']);
    }

    public function testTruncatesLongChunkContent(): void
    {
        $serializer = new MemoryFragmentSerializer();
        $source = new MemorySource('manual', []);
        $longText = str_repeat('a', 300);
        $neuron = new EncyclopedicNeuron(
            source: $source,
            documentRef: 'doc-42',
            chunkIndex: 0,
            totalChunks: 1,
            chunkContent: $longText,
        );

        $payload = $serializer->serialize($neuron);

        $this->assertSame(200, mb_strlen($payload['chunkContent']));
        $this->assertStringEndsWith('...', $payload['chunkContent']);
    }

    public function testEmbeddingIsOmittedFromSerialization(): void
    {
        // L'embedding est volontairement exclu du sérialiseur de debug —
        // il est volumineux et inutile à l'inspection humaine.
        $serializer = new MemoryFragmentSerializer();
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron(
            source: $source,
            documentRef: 'doc-42',
            chunkIndex: 0,
            totalChunks: 1,
            chunkContent: 'x',
            embedding: [0.1, 0.2, 0.3, 0.4, 0.5],
        );

        $payload = $serializer->serialize($neuron);

        $this->assertArrayNotHasKey('embedding', $payload);
    }
}
