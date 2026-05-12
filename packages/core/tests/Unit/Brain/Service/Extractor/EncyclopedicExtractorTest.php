<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor\EncyclopedicExtractor;
use ArnaudMoncondhuy\SynapseCore\Service\ChunkingService;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use PHPUnit\Framework\TestCase;

class EncyclopedicExtractorTest extends TestCase
{
    public function testSupportedAreasReturnsEncyclopedic(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $embedding = $this->createStub(EmbeddingService::class);
        $extractor = new EncyclopedicExtractor($chunking, $embedding);

        $this->assertSame([BrainArea::Encyclopedic], $extractor->supportedAreas());
    }

    public function testChunksAndEmbedsTextFromSource(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $chunking->method('chunkText')->willReturn([
            'chunk 1 content',
            'chunk 2 content',
            'chunk 3 content',
        ]);

        $embedding = $this->createStub(EmbeddingService::class);
        $embedding->method('generateEmbeddings')->willReturn([
            'embeddings' => [
                [0.1, 0.2, 0.3],
                [0.4, 0.5, 0.6],
                [0.7, 0.8, 0.9],
            ],
            'usage' => ['prompt_tokens' => 50, 'total_tokens' => 50],
        ]);

        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', [
            'text' => 'un long document à découper en chunks indexables',
            'document_ref' => 'doc-42',
            'metadata' => ['filename' => 'foo.txt'],
        ]);

        $result = $extractor->extract($source, BrainArea::Encyclopedic);

        $this->assertSame(BrainArea::Encyclopedic, $result->area);
        $this->assertCount(3, $result->neurons);
        $this->assertSame(3, $result->debug['extracted_count']);
        $this->assertSame('doc-42', $result->debug['document_ref']);

        /** @var EncyclopedicNeuron $first */
        $first = $result->neurons[0];
        $this->assertInstanceOf(EncyclopedicNeuron::class, $first);
        $this->assertSame('doc-42', $first->getDocumentRef());
        $this->assertSame(0, $first->getChunkIndex());
        $this->assertSame(3, $first->getTotalChunks());
        $this->assertSame('chunk 1 content', $first->getChunkContent());
        $this->assertSame([0.1, 0.2, 0.3], $first->getEmbedding());
        $this->assertSame(['filename' => 'foo.txt'], $first->getDocMetadata());
        $this->assertSame($source->getId()->toRfc4122(), $first->getSourceUuid()?->toRfc4122());

        /** @var EncyclopedicNeuron $third */
        $third = $result->neurons[2];
        $this->assertSame(2, $third->getChunkIndex());
        $this->assertSame([0.7, 0.8, 0.9], $third->getEmbedding());
    }

    public function testDocumentRefFallsBackToSourceUuid(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $chunking->method('chunkText')->willReturn(['c1']);

        $embedding = $this->createStub(EmbeddingService::class);
        $embedding->method('generateEmbeddings')->willReturn([
            'embeddings' => [[0.1]],
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ]);

        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['text' => 'contenu']);

        $result = $extractor->extract($source, BrainArea::Encyclopedic);

        /** @var EncyclopedicNeuron $neuron */
        $neuron = $result->neurons[0];
        $this->assertSame($source->getId()->toRfc4122(), $neuron->getDocumentRef());
    }

    public function testDocMetadataIsNullWhenAbsent(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $chunking->method('chunkText')->willReturn(['c1']);

        $embedding = $this->createStub(EmbeddingService::class);
        $embedding->method('generateEmbeddings')->willReturn([
            'embeddings' => [[0.1]],
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ]);

        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['text' => 'contenu']);

        $result = $extractor->extract($source, BrainArea::Encyclopedic);

        /** @var EncyclopedicNeuron $neuron */
        $neuron = $result->neurons[0];
        $this->assertNull($neuron->getDocMetadata());
    }

    public function testEmptyResultWhenChunkingProducesNothing(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $chunking->method('chunkText')->willReturn([]);

        $embedding = $this->createStub(EmbeddingService::class);

        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['text' => 'a']);

        $result = $extractor->extract($source, BrainArea::Encyclopedic);

        $this->assertTrue($result->isEmpty());
        $this->assertSame('no_chunks_produced', $result->debug['reason']);
    }

    public function testThrowsWhenWrongAreaRequested(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $embedding = $this->createStub(EmbeddingService::class);
        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['text' => 'x']);

        $this->expectException(ExtractionFailedException::class);

        $extractor->extract($source, BrainArea::Semantic);
    }

    public function testThrowsWhenTextMissing(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $embedding = $this->createStub(EmbeddingService::class);
        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['no_text' => 'oops']);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/non-empty "text"/');

        $extractor->extract($source, BrainArea::Encyclopedic);
    }

    public function testThrowsWhenTextIsBlank(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $embedding = $this->createStub(EmbeddingService::class);
        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['text' => '   ']);

        $this->expectException(ExtractionFailedException::class);

        $extractor->extract($source, BrainArea::Encyclopedic);
    }

    public function testThrowsWhenChunkingFails(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $chunking->method('chunkText')->willThrowException(new \RuntimeException('splitter ko'));

        $embedding = $this->createStub(EmbeddingService::class);

        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['text' => 'contenu']);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/chunking failed/');

        $extractor->extract($source, BrainArea::Encyclopedic);
    }

    public function testThrowsWhenEmbeddingFails(): void
    {
        $chunking = $this->createStub(ChunkingService::class);
        $chunking->method('chunkText')->willReturn(['c1', 'c2']);

        $embedding = $this->createStub(EmbeddingService::class);
        $embedding->method('generateEmbeddings')->willThrowException(new \RuntimeException('provider unreachable'));

        $extractor = new EncyclopedicExtractor($chunking, $embedding);
        $source = new MemorySource('manual', ['text' => 'contenu']);

        $this->expectException(ExtractionFailedException::class);
        $this->expectExceptionMessageMatches('/embedding generation failed/');

        $extractor->extract($source, BrainArea::Encyclopedic);
    }
}
