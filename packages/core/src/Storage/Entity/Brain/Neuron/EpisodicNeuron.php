<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\EpisodicNeuronRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * EpisodicNeuron — un événement situé dans temps × lieu × acteurs.
 *
 * Trace épisodique correspondant à l'aire **Hippocampe** du modèle Brain v3.
 * Stocke un événement vécu / observé avec ses coordonnées contextuelles
 * (quand, où, qui) et un résumé textuel embeddable.
 *
 * Propriétés Hebbiennes :
 * - Plasticité active (renforcement par co-activation)
 * - Decay rapide (jours / semaines, paramétrable)
 * - Peut être consolidé vers SemanticNeuron par le cron de consolidation
 *
 * Cf. {@link docs/brain-v3-design.md} §4.
 */
#[ORM\Entity(repositoryClass: EpisodicNeuronRepository::class)]
#[ORM\Table(name: 'brain_neuron_episodic')]
#[ORM\Index(columns: ['source_uuid'], name: 'idx_brain_neuron_episodic_source')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_brain_neuron_episodic_occurred_at')]
#[ORM\Index(columns: ['sequence_id'], name: 'idx_brain_neuron_episodic_sequence')]
class EpisodicNeuron implements MemoryFragment
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /**
     * Lieu de l'événement. Champ libre — peut être un nom, une adresse,
     * un identifiant de canal (URL, salle virtuelle), ou null.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $location;

    /**
     * Acteurs impliqués dans l'événement.
     *
     * Tableau de chaînes (vocabulaire agnostique côté noyau — l'hôte
     * y met ce qu'il veut : emails, noms, identifiants, etc.).
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $actors;

    /**
     * Résumé textuel embeddable de l'événement.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $eventSummary;

    /**
     * Vecteur d'embedding (sera migré vers pgvector en environnement PostgreSQL).
     *
     * @var list<float>
     */
    #[ORM\Column(type: 'json')]
    private array $embedding;

    /**
     * Identifiant optionnel pour grouper plusieurs neurones épisodiques en
     * une séquence (ex: tous les messages d'une même conversation).
     */
    #[ORM\Column(type: 'uuid', name: 'sequence_id', nullable: true)]
    private ?Uuid $sequenceId;

    /**
     * @param list<string> $actors
     * @param list<float> $embedding
     */
    public function __construct(
        MemorySource $source,
        \DateTimeImmutable $occurredAt,
        string $eventSummary,
        array $actors = [],
        array $embedding = [],
        ?string $location = null,
        ?Uuid $sequenceId = null,
    ) {
        $this->id = Uuid::v7();
        $this->sourceUuid = $source->getId();
        $this->occurredAt = $occurredAt;
        $this->eventSummary = $eventSummary;
        $this->actors = $actors;
        $this->embedding = $embedding;
        $this->location = $location;
        $this->sequenceId = $sequenceId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArea(): BrainArea
    {
        return BrainArea::Episodic;
    }

    public function getSourceUuid(): ?Uuid
    {
        return $this->sourceUuid;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    /**
     * @return list<string>
     */
    public function getActors(): array
    {
        return $this->actors;
    }

    public function getEventSummary(): string
    {
        return $this->eventSummary;
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
    public function setEmbedding(array $embedding): self
    {
        $this->embedding = $embedding;

        return $this;
    }

    public function getSequenceId(): ?Uuid
    {
        return $this->sequenceId;
    }

    public function setSequenceId(?Uuid $sequenceId): self
    {
        $this->sequenceId = $sequenceId;

        return $this;
    }
}
