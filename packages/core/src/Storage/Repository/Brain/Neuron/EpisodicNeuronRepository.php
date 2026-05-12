<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EpisodicNeuron;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<EpisodicNeuron>
 */
class EpisodicNeuronRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EpisodicNeuron::class);
    }

    /**
     * Tous les neurones épisodiques dérivés d'une source donnée.
     *
     * @return list<EpisodicNeuron>
     */
    public function findBySource(Uuid $sourceUuid): array
    {
        /** @var list<EpisodicNeuron> $result */
        $result = $this->findBy(['sourceUuid' => $sourceUuid]);

        return $result;
    }

    /**
     * Neurones d'une séquence (ex: tous les messages d'une conversation),
     * ordonnés par occurredAt ascendant.
     *
     * @return list<EpisodicNeuron>
     */
    public function findBySequence(Uuid $sequenceId): array
    {
        /** @var list<EpisodicNeuron> $result */
        $result = $this->findBy(
            ['sequenceId' => $sequenceId],
            ['occurredAt' => 'ASC'],
        );

        return $result;
    }
}
