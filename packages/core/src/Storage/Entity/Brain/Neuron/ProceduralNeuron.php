<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron\ProceduralNeuronRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * ProceduralNeuron — un workflow / procédure typée.
 *
 * Trace procédurale correspondant à l'aire **Ganglions de la base** du modèle
 * Brain v3. Stocke une séquence d'étapes typées avec ses conditions de
 * déclenchement et son taux de succès.
 *
 * Pas d'embedding : la recherche se fait par **trigger pattern** (matching
 * structurel), pas par similarité sémantique. Les ganglions activent une
 * procédure quand un pattern d'entrée correspond — pas quand un texte est
 * "proche".
 *
 * Propriétés :
 * - Plasticité Hebbienne via `successRate` qui évolue à chaque exécution
 *   (renforcement si succès, dégradation si échec)
 * - Decay basé sur `executionCount` + `lastExecutedAt` (procédure jamais
 *   utilisée → finit par disparaître via le cron de pruning du jalon 8)
 * - Source potentiellement nullable : une procédure peut être créée manuellement
 *   (admin) sans source brute associée
 *
 * Cf. {@link docs/brain-v3-design.md} §4.
 */
#[ORM\Entity(repositoryClass: ProceduralNeuronRepository::class)]
#[ORM\Table(name: 'brain_neuron_procedural')]
#[ORM\Index(columns: ['source_uuid'], name: 'idx_brain_neuron_procedural_source')]
#[ORM\Index(columns: ['name'], name: 'idx_brain_neuron_procedural_name')]
#[ORM\Index(columns: ['last_executed_at'], name: 'idx_brain_neuron_procedural_last_executed')]
class ProceduralNeuron implements MemoryFragment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * UUID de la MemorySource d'origine.
     *
     * Nullable contrairement aux autres neurones : une procédure peut être
     * définie manuellement (admin créateur de routines) sans source brute.
     */
    #[ORM\Column(type: 'uuid', name: 'source_uuid', nullable: true)]
    private ?Uuid $sourceUuid;

    /**
     * Nom court de la procédure (clé fonctionnelle).
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /**
     * Conditions de déclenchement (pattern d'entrée).
     *
     * Format libre côté noyau — l'app hôte définit la sémantique. Exemple
     * abstrait : `{"event_type": "...", "threshold": "..."}`. Vocabulaire
     * agnostique côté Brain.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', name: 'trigger_pattern')]
    private array $triggerPattern;

    /**
     * Séquence d'étapes typées à exécuter.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $steps;

    /**
     * Pré/post-conditions optionnelles.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $conditions;

    /**
     * Taux de succès historique — 0 à 1. Renforcement Hebbien à chaque
     * exécution (incrément si succès, décroissance si échec).
     */
    #[ORM\Column(type: Types::FLOAT, name: 'success_rate')]
    private float $successRate;

    /**
     * Nombre d'exécutions cumulées. Utile pour le decay (procédure jamais
     * utilisée → pruning au jalon 8).
     */
    #[ORM\Column(type: Types::INTEGER, name: 'execution_count')]
    private int $executionCount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'last_executed_at', nullable: true)]
    private ?\DateTimeImmutable $lastExecutedAt;

    /**
     * @param array<string, mixed> $triggerPattern
     * @param list<array<string, mixed>> $steps
     * @param array<string, mixed> $conditions
     */
    public function __construct(
        ?MemorySource $source,
        string $name,
        array $triggerPattern,
        array $steps,
        array $conditions = [],
        float $successRate = 0.5,
    ) {
        $this->id = Uuid::v7();
        $this->sourceUuid = $source?->getId();
        $this->name = $name;
        $this->triggerPattern = $triggerPattern;
        $this->steps = $steps;
        $this->conditions = $conditions;
        $this->successRate = $successRate;
        $this->executionCount = 0;
        $this->lastExecutedAt = null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArea(): BrainArea
    {
        return BrainArea::Procedural;
    }

    public function getSourceUuid(): ?Uuid
    {
        return $this->sourceUuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTriggerPattern(): array
    {
        return $this->triggerPattern;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getSuccessRate(): float
    {
        return $this->successRate;
    }

    public function getExecutionCount(): int
    {
        return $this->executionCount;
    }

    public function getLastExecutedAt(): ?\DateTimeImmutable
    {
        return $this->lastExecutedAt;
    }

    /**
     * Enregistre l'exécution de la procédure et met à jour le successRate.
     *
     * Plasticité Hebbienne simple : moyenne pondérée entre successRate
     * actuel et résultat de cette exécution (1.0 si succès, 0.0 si échec),
     * avec un facteur d'apprentissage de 0.1 (les anciennes exécutions
     * comptent ~10× plus qu'une seule récente).
     */
    public function recordExecution(bool $success): self
    {
        $outcome = $success ? 1.0 : 0.0;
        $learningRate = 0.1;
        $this->successRate = (1.0 - $learningRate) * $this->successRate + $learningRate * $outcome;
        $this->successRate = max(0.0, min(1.0, $this->successRate));
        ++$this->executionCount;
        $this->lastExecutedAt = new \DateTimeImmutable();

        return $this;
    }
}
