<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\SemanticNeuronRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * SemanticNeuron — un fait stable au format subject-predicate-value.
 *
 * Trace sémantique correspondant à l'aire **Néocortex** du modèle Brain v3.
 * Stocke une affirmation atomique avec un niveau de confiance et un compteur
 * de corroborations.
 *
 * Propriétés :
 * - **Sources multiples** : un fait peut être corroboré par plusieurs sources
 *   différentes. On stocke leurs UUIDs dans un array — chaque corroboration
 *   incrémente `evidenceCount` et augmente la confiance.
 * - Plasticité Hebbienne active, decay lent (mois / années).
 * - Cible privilégiée des consolidations depuis l'épisodique.
 *
 * Cf. {@link docs/brain-v3-design.md} §4 et §9 (convergence mémorielle).
 */
#[ORM\Entity(repositoryClass: SemanticNeuronRepository::class)]
#[ORM\Table(name: 'brain_neuron_semantic')]
#[ORM\Index(columns: ['subject'], name: 'idx_brain_neuron_semantic_subject')]
#[ORM\Index(columns: ['predicate'], name: 'idx_brain_neuron_semantic_predicate')]
#[ORM\Index(columns: ['last_corroborated_at'], name: 'idx_brain_neuron_semantic_corroborated')]
class SemanticNeuron implements MemoryFragment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * UUIDs des sources qui corroborent ce fait.
     *
     * Une nouvelle source qui produit le même fait étend ce tableau et
     * incrémente {@see $evidenceCount}. `getSourceUuid()` retourne la
     * première source (origine) pour le contrat MemoryFragment.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', name: 'source_uuids')]
    private array $sourceUuids;

    #[ORM\Column(type: Types::TEXT)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $predicate;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    /**
     * Confiance dans la véracité du fait — 0 à 1. Distinct du poids des
     * synapses (force d'activation). Augmente avec evidenceCount, peut
     * baisser sur contradiction.
     */
    #[ORM\Column(type: Types::FLOAT)]
    private float $confidence;

    /**
     * Vecteur d'embedding (sera migré vers pgvector en environnement PostgreSQL).
     *
     * @var list<float>
     */
    #[ORM\Column(type: 'json')]
    private array $embedding;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'last_corroborated_at')]
    private \DateTimeImmutable $lastCorroboratedAt;

    /**
     * @param list<float> $embedding
     */
    public function __construct(
        Uuid $firstSource,
        string $subject,
        string $predicate,
        string $value,
        float $confidence = 0.5,
        array $embedding = [],
    ) {
        $this->id = Uuid::v7();
        $this->sourceUuids = [$firstSource->toRfc4122()];
        $this->subject = $subject;
        $this->predicate = $predicate;
        $this->value = $value;
        $this->confidence = $confidence;
        $this->embedding = $embedding;
        $this->lastCorroboratedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArea(): BrainArea
    {
        return BrainArea::Semantic;
    }

    /**
     * Retourne la **première** source — celle qui a créé le neurone.
     *
     * Pour obtenir toutes les sources corroborantes, voir {@see getSourceUuids()}.
     */
    public function getSourceUuid(): ?Uuid
    {
        if ([] === $this->sourceUuids) {
            return null;
        }

        return Uuid::fromString($this->sourceUuids[0]);
    }

    /**
     * @return list<Uuid>
     */
    public function getSourceUuids(): array
    {
        return array_map(static fn (string $u): Uuid => Uuid::fromString($u), $this->sourceUuids);
    }

    public function getEvidenceCount(): int
    {
        return count($this->sourceUuids);
    }

    /**
     * Enregistre une corroboration : ajoute la source (si pas déjà présente),
     * met à jour lastCorroboratedAt. La confiance n'est PAS modifiée ici —
     * c'est le rôle d'un service dédié (au jalon 5).
     */
    public function corroborate(Uuid $newSource): self
    {
        $rfc = $newSource->toRfc4122();
        if (!in_array($rfc, $this->sourceUuids, true)) {
            $this->sourceUuids[] = $rfc;
        }
        $this->lastCorroboratedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getPredicate(): string
    {
        return $this->predicate;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function setConfidence(float $confidence): self
    {
        $this->confidence = $confidence;

        return $this;
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

    public function getLastCorroboratedAt(): \DateTimeImmutable
    {
        return $this->lastCorroboratedAt;
    }
}
