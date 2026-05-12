<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Brain\Service\Extractor;

use ArnaudMoncondhuy\SynapseCore\Brain\Exception\ExtractionFailedException;
use ArnaudMoncondhuy\SynapseCore\Service\ChunkingService;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;

/**
 * Extracteur encyclopédique : découpe un document texte de la source en chunks
 * vectorisés (EncyclopedicNeuron[]).
 *
 * **Pas d'appel LLM d'extraction** (contrairement à SemanticExtractor /
 * EpisodicExtractor). Le LLM n'intervient qu'au moment de l'embedding via
 * EmbeddingService. La source est censée porter du texte indexable, pas du
 * sens à extraire.
 *
 * Convention sur la source :
 * - `provider` : indique le canal d'origine (générique côté noyau)
 * - `rawPayload` : doit contenir au minimum une clé `text` avec le contenu
 *   à chunker. Optionnel : `document_ref` (identifiant document) et
 *   `metadata` (libre, transmise vers EncyclopedicNeuron::docMetadata).
 *
 * Absorbe la sémantique du `RagManager` actuel sans le casser (le jalon 8
 * retirera l'ancien). Cf. {@link docs/brain/03-audit-existant.md}.
 */
final readonly class EncyclopedicExtractor implements NeuronExtractorInterface
{
    public function __construct(
        private ChunkingService $chunkingService,
        private EmbeddingService $embeddingService,
    ) {
    }

    /**
     * @return list<BrainArea>
     */
    public function supportedAreas(): array
    {
        return [BrainArea::Encyclopedic];
    }

    public function extract(MemorySource $source, BrainArea $targetArea): ExtractionResult
    {
        if (BrainArea::Encyclopedic !== $targetArea) {
            throw new ExtractionFailedException($source, $targetArea, sprintf('EncyclopedicExtractor does not support area "%s"', $targetArea->value));
        }

        $payload = $source->getRawPayload();
        $text = $payload['text'] ?? null;

        if (!is_string($text) || '' === trim($text)) {
            throw new ExtractionFailedException($source, $targetArea, 'source payload must contain a non-empty "text" key for encyclopedic extraction');
        }

        $documentRef = (string) ($payload['document_ref'] ?? $source->getId()->toRfc4122());

        $docMetadata = null;
        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            /** @var array<string, mixed> $docMetadata */
            $docMetadata = $payload['metadata'];
        }

        try {
            $chunks = $this->chunkingService->chunkText($text);
        } catch (\Throwable $e) {
            throw new ExtractionFailedException($source, $targetArea, 'chunking failed: '.$e->getMessage(), $e);
        }

        if ([] === $chunks) {
            return new ExtractionResult(
                area: BrainArea::Encyclopedic,
                neurons: [],
                debug: ['extracted_count' => 0, 'reason' => 'no_chunks_produced'],
            );
        }

        try {
            /** @var array{embeddings: list<list<float>>, usage: array{prompt_tokens: int, total_tokens: int}} $embeddingResult */
            $embeddingResult = $this->embeddingService->generateEmbeddings(
                $chunks,
                null,
                'brain_encyclopedic',
            );
        } catch (\Throwable $e) {
            throw new ExtractionFailedException($source, $targetArea, 'embedding generation failed: '.$e->getMessage(), $e);
        }

        $embeddings = $embeddingResult['embeddings'];
        $total = count($chunks);
        $neurons = [];

        foreach ($chunks as $index => $chunkContent) {
            $embedding = $embeddings[$index] ?? [];

            $neurons[] = new EncyclopedicNeuron(
                source: $source,
                documentRef: $documentRef,
                chunkIndex: $index,
                totalChunks: $total,
                chunkContent: $chunkContent,
                embedding: $embedding,
                docMetadata: $docMetadata,
            );
        }

        return new ExtractionResult(
            area: BrainArea::Encyclopedic,
            neurons: $neurons,
            debug: [
                'extracted_count' => count($neurons),
                'document_ref' => $documentRef,
                'embedding_usage' => $embeddingResult['usage'] ?? [],
            ],
        );
    }
}
