<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseAgent>
 */
class SynapseAgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseAgent::class);
    }

    /**
     * Trouve tous les agents persistants actifs triés par ordre d'affichage.
     * Exclut les éphémères (créés via MCP ou ArchitectAgent).
     *
     * @return array<int, SynapseAgent>
     */
    public function findAllActive(): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->andWhere('m.isActive = true')
            ->andWhere('m.visibleInChat = true')
            ->andWhere('m.isEphemeral = false')
            ->orderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Trouve tous les agents persistants triés (builtin d'abord, puis sortOrder).
     * Utilisé pour l'affichage admin. Exclut les éphémères — ceux-ci sont
     * exposés séparément via {@see findEphemeral()}.
     *
     * @return array<int, SynapseAgent>
     */
    public function findAllOrdered(): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->andWhere('m.isEphemeral = false')
            ->orderBy('m.isBuiltin', 'DESC')
            ->addOrderBy('m.sortOrder', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Trouve un agent par sa clé unique (inclut les éphémères — nécessaire
     * pour la résolution par AgentResolver).
     */
    public function findByKey(string $key): ?SynapseAgent
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Retourne tous les agents éphémères, triés du plus récent au plus ancien.
     *
     * @return array<int, SynapseAgent>
     */
    public function findEphemeral(): array
    {
        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->andWhere('m.isEphemeral = true')
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Retourne tous les agents éphémères dont la rétention est expirée —
     * cible du GC et du cleanup MCP.
     *
     * @return array<int, SynapseAgent>
     */
    public function findExpiredEphemeral(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        /** @var array<int, SynapseAgent> $result */
        $result = $this->createQueryBuilder('m')
            ->andWhere('m.isEphemeral = true')
            ->andWhere('m.retentionUntil IS NULL OR m.retentionUntil < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @deprecated Utiliser {@see findEphemeral()}.
     *
     * @return array<int, SynapseAgent>
     */
    public function findSandbox(): array
    {
        return $this->findEphemeral();
    }
}
