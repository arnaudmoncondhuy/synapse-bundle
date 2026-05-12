<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\EncyclopedicNeuron;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<EncyclopedicNeuron>
 */
class EncyclopedicNeuronRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EncyclopedicNeuron::class);
    }

    /**
     * Tous les chunks d'une source donnée, ordonnés par index de chunk.
     *
     * @return list<EncyclopedicNeuron>
     */
    public function findBySource(Uuid $sourceUuid): array
    {
        /** @var list<EncyclopedicNeuron> $result */
        $result = $this->findBy(
            ['sourceUuid' => $sourceUuid],
            ['chunkIndex' => 'ASC'],
        );

        return $result;
    }

    /**
     * Tous les chunks d'une référence document donnée, ordonnés par index.
     *
     * Utile pour reconstruire le contexte voisin (chunks adjacents) lors
     * d'un retrieval qui matche un chunk au milieu d'un long document.
     *
     * @return list<EncyclopedicNeuron>
     */
    public function findByDocumentRef(string $documentRef): array
    {
        /** @var list<EncyclopedicNeuron> $result */
        $result = $this->findBy(
            ['documentRef' => $documentRef],
            ['chunkIndex' => 'ASC'],
        );

        return $result;
    }
}
