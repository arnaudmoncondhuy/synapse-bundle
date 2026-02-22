<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Storage\Repository;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\DebugLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DebugLog>
 */
class DebugLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DebugLog::class);
    }

    /**
     * Récupère un log de debug par ID
     */
    public function findByDebugId(string $debugId): ?DebugLog
    {
        return $this->findOneBy(['debugId' => $debugId]);
    }

    /**
     * Récupère les logs de debug récents
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Supprime les logs de debug antérieurs à une date donnée
     */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->executeStatement();
    }
}
