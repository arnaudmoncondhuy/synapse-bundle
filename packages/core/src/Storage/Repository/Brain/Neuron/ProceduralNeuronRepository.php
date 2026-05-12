<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain\Neuron;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\Neuron\ProceduralNeuron;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProceduralNeuron>
 */
class ProceduralNeuronRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProceduralNeuron::class);
    }

    /**
     * Récupère une procédure par nom (clé fonctionnelle non unique au niveau
     * SQL, mais conventionnellement unique pour une app hôte donnée).
     *
     * @return list<ProceduralNeuron>
     */
    public function findByName(string $name): array
    {
        /** @var list<ProceduralNeuron> $result */
        $result = $this->findBy(['name' => $name], ['executionCount' => 'DESC']);

        return $result;
    }

    /**
     * Procédures candidates à l'élagage : jamais exécutées ou pas exécutées
     * depuis longtemps. Utilisé par le cron de pruning du jalon 8.
     *
     * @return list<ProceduralNeuron>
     */
    public function findCandidatesForPruning(\DateTimeImmutable $olderThan, int $minExecutions = 1): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.lastExecutedAt IS NULL OR p.lastExecutedAt < :threshold')
            ->andWhere('p.executionCount < :minExec')
            ->setParameter('threshold', $olderThan)
            ->setParameter('minExec', $minExecutions);

        /** @var list<ProceduralNeuron> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
