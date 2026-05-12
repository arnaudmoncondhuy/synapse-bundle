<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain;

use ArnaudMoncondhuy\SynapseCore\Brain\Contract\MemoryFragment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Synapse;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\BrainArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Synapse>
 */
class SynapseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Synapse::class);
    }

    /**
     * Synapses ayant le neurone donné en source.
     *
     * @return list<Synapse>
     */
    public function findOutgoing(MemoryFragment $neuron): array
    {
        /** @var list<Synapse> $result */
        $result = $this->findBy([
            'sourceNeuronArea' => $neuron->getArea(),
            'sourceNeuronId' => $neuron->getId(),
        ]);

        return $result;
    }

    /**
     * Synapses ayant le neurone donné en target.
     *
     * @return list<Synapse>
     */
    public function findIncoming(MemoryFragment $neuron): array
    {
        /** @var list<Synapse> $result */
        $result = $this->findBy([
            'targetNeuronArea' => $neuron->getArea(),
            'targetNeuronId' => $neuron->getId(),
        ]);

        return $result;
    }

    /**
     * Cherche une synapse exacte entre deux neurones (sans tenir compte des
     * autres dimensions). Utile pour incrémenter une corroboration au lieu
     * de créer un doublon.
     */
    public function findBetween(MemoryFragment $source, MemoryFragment $target): ?Synapse
    {
        return $this->findOneBy([
            'sourceNeuronArea' => $source->getArea(),
            'sourceNeuronId' => $source->getId(),
            'targetNeuronArea' => $target->getArea(),
            'targetNeuronId' => $target->getId(),
        ]);
    }

    /**
     * Toutes les synapses portant sur un neurone (sortantes ou entrantes).
     *
     * @return list<Synapse>
     */
    public function findAllRelated(BrainArea $area, Uuid $neuronId): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('(s.sourceNeuronArea = :area AND s.sourceNeuronId = :id) OR (s.targetNeuronArea = :area AND s.targetNeuronId = :id)')
            ->setParameter('area', $area)
            ->setParameter('id', $neuronId);

        /** @var list<Synapse> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
