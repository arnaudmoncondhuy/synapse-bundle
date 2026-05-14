<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\EmbeddableNeuron;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EncyclopedicNeuronRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * EncyclopedicNeuron — un chunk de document indexable.
 *
 * Trace encyclopédique correspondant à l'aire **Cortex temporal** du modèle
 * Brain v3. Stocke un fragment de document (texte chunké) avec son embedding
 * et une référence au document d'origine.
 *
 * Absorbe la sémantique de l'actuel `SynapseRagDocument`. Le jalon 8 prévoit
 * la migration des apps consommatrices vers ce neurone (retirera alors la
 * vieille table `synapse_rag_document`).
 *
 * Propriétés Hebbiennes :
 * - Plasticité active (renforcement par co-activation lors de retrieval)
 * - Decay négligeable (les documents indexés restent stables dans le temps)
 * - Pas de consolidation vers d'autres aires (les chunks sont déjà du savoir
 *   structuré ; la consolidation produirait du sémantique, mais ça transite
 *   plutôt par un cycle d'extraction LLM)
 *
 * Cf. {@link docs/brain-v3-design.md} §4.
 */
#[ORM\Entity(repositoryClass: EncyclopedicNeuronRepository::class)]
#[ORM\Table(name: 'brain_neuron_encyclopedic')]
#[ORM\Index(columns: ['source_uuid'], name: 'idx_brain_neuron_encyclopedic_source')]
#[ORM\Index(columns: ['document_ref'], name: 'idx_brain_neuron_encyclopedic_doc_ref')]
#[ORM\Index(columns: ['source_uuid', 'chunk_index'], name: 'idx_brain_neuron_encyclopedic_source_chunk')]
class EncyclopedicNeuron implements EmbeddableNeuron
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * UUID de la MemorySource dont ce neurone est dérivé.
     *
     * On stocke l'UUID (pas une ManyToOne) car :
     * 1. Pas de cascade ORM côté entité (le couplage est minimal)
     * 2. Cohérent avec le pattern polymorphe de Synapse
     * 3. ON DELETE CASCADE géré au niveau SQL via la FK
     */
    #[ORM\Column(type: 'uuid', name: 'source_uuid')]
    private Uuid $sourceUuid;

    /**
     * Référence au document d'origine — généralement un identifiant côté hôte
     * (filename, drive_file_id, url, hash, ...). Vocabulaire agnostique côté
     * noyau.
     */
    #[ORM\Column(type: Types::STRING, length: 255, name: 'document_ref')]
    private string $documentRef;

    /**
     * Index du chunk dans le document d'origine (0-based).
     */
    #[ORM\Column(type: Types::INTEGER, name: 'chunk_index')]
    private int $chunkIndex;

    /**
     * Nombre total de chunks produits pour ce document (utile pour reconstruire
     * le contexte voisin lors d'un retrieval).
     */
    #[ORM\Column(type: Types::INTEGER, name: 'total_chunks')]
    private int $totalChunks;

    /**
     * Contenu textuel du chunk.
     */
    #[ORM\Column(type: Types::TEXT, name: 'chunk_content')]
    private string $chunkContent;

    /**
     * Vecteur d'embedding (sera migré vers pgvector en environnement PostgreSQL).
     *
     * @var list<float>
     */
    #[ORM\Column(type: 'json')]
    private array $embedding;

    /**
     * Métadonnées libres fournies par l'hôte (filename, drive_id, url, folder,
     * mime_type, ...).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true, name: 'doc_metadata')]
    private ?array $docMetadata;

    /**
     * @param list<float> $embedding
     * @param array<string, mixed>|null $docMetadata
     */
    public function __construct(
        MemorySource $source,
        string $documentRef,
        int $chunkIndex,
        int $totalChunks,
        string $chunkContent,
        array $embedding = [],
        ?array $docMetadata = null,
    ) {
        $this->id = Uuid::v7();
        $this->sourceUuid = $source->getId();
        $this->documentRef = $documentRef;
        $this->chunkIndex = $chunkIndex;
        $this->totalChunks = $totalChunks;
        $this->chunkContent = $chunkContent;
        $this->embedding = $embedding;
        $this->docMetadata = $docMetadata;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArea(): BrainArea
    {
        return BrainArea::Encyclopedic;
    }

    public function getSourceUuid(): ?Uuid
    {
        return $this->sourceUuid;
    }

    public function getDocumentRef(): string
    {
        return $this->documentRef;
    }

    public function getChunkIndex(): int
    {
        return $this->chunkIndex;
    }

    public function getTotalChunks(): int
    {
        return $this->totalChunks;
    }

    public function getChunkContent(): string
    {
        return $this->chunkContent;
    }

    /**
     * @return list<float>
     */
    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    /**
     * @param list<float> $embedding
     */
    public function setEmbedding(array $embedding): void
    {
        $this->embedding = $embedding;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDocMetadata(): ?array
    {
        return $this->docMetadata;
    }
}
