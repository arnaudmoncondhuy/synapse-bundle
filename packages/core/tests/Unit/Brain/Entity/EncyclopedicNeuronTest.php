<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Entity;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

class EncyclopedicNeuronTest extends TestCase
{
    public function testImplementsMemoryFragmentContract(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron($source, 'doc-1', 0, 1, 'contenu');

        $this->assertInstanceOf(MemoryFragment::class, $neuron);
    }

    public function testGetAreaReturnsEncyclopedic(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron($source, 'doc-1', 0, 1, 'contenu');

        $this->assertSame(BrainArea::Encyclopedic, $neuron->getArea());
    }

    public function testGetIdReturnsUuidV7(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron($source, 'doc-1', 0, 1, 'contenu');

        $this->assertInstanceOf(UuidV7::class, $neuron->getId());
    }

    public function testSourceUuidPointsToSourceId(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron($source, 'doc-1', 0, 1, 'contenu');

        $this->assertSame(
            $source->getId()->toRfc4122(),
            $neuron->getSourceUuid()?->toRfc4122(),
        );
    }

    public function testConstructorAssignsAllFields(): void
    {
        $source = new MemorySource('manual', []);
        $metadata = ['filename' => 'foo.pdf', 'url' => 'https://example/foo.pdf'];

        $neuron = new EncyclopedicNeuron(
            $source,
            'doc-ref-42',
            3,
            10,
            'le contenu du chunk',
            [0.1, 0.2, 0.3],
            $metadata,
        );

        $this->assertSame('doc-ref-42', $neuron->getDocumentRef());
        $this->assertSame(3, $neuron->getChunkIndex());
        $this->assertSame(10, $neuron->getTotalChunks());
        $this->assertSame('le contenu du chunk', $neuron->getChunkContent());
        $this->assertSame([0.1, 0.2, 0.3], $neuron->getEmbedding());
        $this->assertSame($metadata, $neuron->getDocMetadata());
    }

    public function testDefaultsAreEmptyOrNull(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron($source, 'doc-1', 0, 1, 'contenu');

        $this->assertSame([], $neuron->getEmbedding());
        $this->assertNull($neuron->getDocMetadata());
    }

    public function testEmbeddingIsMutable(): void
    {
        $source = new MemorySource('manual', []);
        $neuron = new EncyclopedicNeuron($source, 'doc-1', 0, 1, 'contenu');

        $neuron->setEmbedding([0.5, 0.6]);

        $this->assertSame([0.5, 0.6], $neuron->getEmbedding());
    }
}
