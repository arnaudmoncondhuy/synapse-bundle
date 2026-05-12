<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseEdgeType;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapsePolarity;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\SynapseRelationType;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\SynapseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Synapse — connexion polymorphe entre deux neurones d'aires éventuellement
 * différentes.
 *
 * Dépasse le modèle Graph RAG (poids unique par edge) en portant **5 dimensions
 * sémantiques distinctes** (cf. {@link docs/brain-v3-design.md} §6) :
 *
 * - **weight** : force d'activation historique (0-1, scoring du retrieval)
 * - **polarity** : excitatory ou inhibitory (modélise contradictions)
 * - **relationType** : 9 types (causal, corroborates, contradicts, ...)
 *   pour le raisonnement typé
 * - **confidence** : fiabilité (≠ force) 0-1
 * - **evidenceCount** : nombre de corroborations indépendantes
 *
 * **Polymorphisme** : les neurones source et target peuvent appartenir à
 * n'importe quelle aire. On stocke (aire, uuid) plutôt qu'une FK Doctrine —
 * l'intégrité est gérée en code via {@see MemoryFragment} (charte §2.3 :
 * pas d'héritage Doctrine, schémas spécialisés).
 *
 * **Régime** :
 * - `edgeType = Association` → plasticité Hebbienne active, decay applicable
 * - `edgeType = Transduction` → câblage fixe pour audit, pas de plasticité
 */
#[ORM\Entity(repositoryClass: SynapseRepository::class)]
#[ORM\Table(name: 'brain_synapse')]
#[ORM\Index(columns: ['source_neuron_area', 'source_neuron_id'], name: 'idx_brain_synapse_source')]
#[ORM\Index(columns: ['target_neuron_area', 'target_neuron_id'], name: 'idx_brain_synapse_target')]
#[ORM\Index(columns: ['relation_type'], name: 'idx_brain_synapse_relation_type')]
#[ORM\Index(columns: ['context_id'], name: 'idx_brain_synapse_context')]
#[ORM\Index(columns: ['last_activated_at'], name: 'idx_brain_synapse_last_activated')]
class Synapse
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 20, name: 'source_neuron_area', enumType: BrainArea::class)]
    private BrainArea $sourceNeuronArea;

    #[ORM\Column(type: 'uuid', name: 'source_neuron_id')]
    private Uuid $sourceNeuronId;

    #[ORM\Column(type: Types::STRING, length: 20, name: 'target_neuron_area', enumType: BrainArea::class)]
    private BrainArea $targetNeuronArea;

    #[ORM\Column(type: 'uuid', name: 'target_neuron_id')]
    private Uuid $targetNeuronId;

    /**
     * Force d'activation 0-1.
     */
    #[ORM\Column(type: Types::FLOAT)]
    private float $weight;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: SynapsePolarity::class)]
    private SynapsePolarity $polarity;

    #[ORM\Column(type: Types::STRING, length: 20, name: 'relation_type', enumType: SynapseRelationType::class)]
    private SynapseRelationType $relationType;

    /**
     * Fiabilité 0-1 — distincte du weight (force).
     *
     * 100 sources sûres ≠ activé 100 fois historiquement (charte §6 design).
     */
    #[ORM\Column(type: Types::FLOAT)]
    private float $confidence;

    /**
     * Nombre de corroborations indépendantes.
     */
    #[ORM\Column(type: Types::INTEGER, name: 'evidence_count')]
    private int $evidenceCount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'last_activated_at')]
    private \DateTimeImmutable $lastActivatedAt;

    /**
     * Identifiant du functional network contextuel actif lors de la création.
     * Nullable — null = synapse "neutre" (pas liée à un mode).
     */
    #[ORM\Column(type: 'uuid', name: 'context_id', nullable: true)]
    private ?Uuid $contextId;

    #[ORM\Column(type: Types::STRING, length: 16, name: 'edge_type', enumType: SynapseEdgeType::class)]
    private SynapseEdgeType $edgeType;

    public function __construct(
        MemoryFragment $source,
        MemoryFragment $target,
        float $weight = 0.1,
        SynapsePolarity $polarity = SynapsePolarity::Excitatory,
        SynapseRelationType $relationType = SynapseRelationType::Generic,
        float $confidence = 0.5,
        int $evidenceCount = 1,
        ?Uuid $contextId = null,
        ?SynapseEdgeType $edgeType = null,
    ) {
        $this->id = Uuid::v7();
        $this->sourceNeuronArea = $source->getArea();
        $this->sourceNeuronId = $source->getId();
        $this->targetNeuronArea = $target->getArea();
        $this->targetNeuronId = $target->getId();
        $this->weight = $weight;
        $this->polarity = $polarity;
        $this->relationType = $relationType;
        $this->confidence = $confidence;
        $this->evidenceCount = $evidenceCount;
        $this->lastActivatedAt = new \DateTimeImmutable();
        $this->contextId = $contextId;
        $this->edgeType = $edgeType ?? self::inferEdgeType($source->getArea(), $target->getArea());
    }

    /**
     * Détermine le régime par défaut selon les aires impliquées :
     * - si au moins une aire est de transduction (Sensory, Motor)
     *   → Transduction (câblage fixe)
     * - sinon → Association (plasticité Hebbienne)
     */
    public static function inferEdgeType(BrainArea $sourceArea, BrainArea $targetArea): SynapseEdgeType
    {
        if ($sourceArea->isTransduction() || $targetArea->isTransduction()) {
            return SynapseEdgeType::Transduction;
        }

        return SynapseEdgeType::Association;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceNeuronArea(): BrainArea
    {
        return $this->sourceNeuronArea;
    }

    public function getSourceNeuronId(): Uuid
    {
        return $this->sourceNeuronId;
    }

    public function getTargetNeuronArea(): BrainArea
    {
        return $this->targetNeuronArea;
    }

    public function getTargetNeuronId(): Uuid
    {
        return $this->targetNeuronId;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): self
    {
        $this->weight = $weight;

        return $this;
    }

    public function getPolarity(): SynapsePolarity
    {
        return $this->polarity;
    }

    public function setPolarity(SynapsePolarity $polarity): self
    {
        $this->polarity = $polarity;

        return $this;
    }

    public function getRelationType(): SynapseRelationType
    {
        return $this->relationType;
    }

    public function setRelationType(SynapseRelationType $relationType): self
    {
        $this->relationType = $relationType;

        return $this;
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

    public function getEvidenceCount(): int
    {
        return $this->evidenceCount;
    }

    /**
     * Incrémente le compteur d'evidence et met à jour lastActivatedAt.
     */
    public function recordCorroboration(): self
    {
        ++$this->evidenceCount;
        $this->lastActivatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastActivatedAt(): \DateTimeImmutable
    {
        return $this->lastActivatedAt;
    }

    public function getContextId(): ?Uuid
    {
        return $this->contextId;
    }

    public function getEdgeType(): SynapseEdgeType
    {
        return $this->edgeType;
    }
}
