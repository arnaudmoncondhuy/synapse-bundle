<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseWorkflow>
 */
class SynapseWorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseWorkflow::class);
    }

    /**
     * Tous les workflows persistants triés pour l'admin (builtin d'abord, puis sortOrder, puis nom).
     * Exclut les éphémères — ceux-ci sont exposés séparément via {@see findEphemeral()}.
     *
     * @return array<int, SynapseWorkflow>
     */
    public function findAllOrdered(): array
    {
        /** @var array<int, SynapseWorkflow> $result */
        $result = $this->createQueryBuilder('w')
            ->andWhere('w.isEphemeral = false')
            ->orderBy('w.isBuiltin', 'DESC')
            ->addOrderBy('w.sortOrder', 'ASC')
            ->addOrderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Recherche un workflow actif par sa clé unique (appelable par le moteur d'exécution).
     * Inclut les éphémères — nécessaire pour l'exécution via MCP.
     */
    public function findActiveByKey(string $workflowKey): ?SynapseWorkflow
    {
        return $this->findOneBy(['workflowKey' => $workflowKey, 'isActive' => true]);
    }

    /**
     * Recherche par clé sans filtre (inclut éphémères — pour admin edit et MCP).
     */
    public function findByKey(string $workflowKey): ?SynapseWorkflow
    {
        return $this->findOneBy(['workflowKey' => $workflowKey]);
    }

    /**
     * Retourne tous les workflows éphémères (pour l'admin section dédiée et le GC).
     * Trié du plus récent au plus ancien.
     *
     * @return array<int, SynapseWorkflow>
     */
    public function findEphemeral(): array
    {
        /** @var array<int, SynapseWorkflow> $result */
        $result = $this->createQueryBuilder('w')
            ->andWhere('w.isEphemeral = true')
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Retourne tous les éphémères dont la fenêtre de rétention est dépassée —
     * cible du `synapse:ephemeral:gc` et du `cleanup_sandbox` MCP.
     * Inclut les éphémères avec `retention_until IS NULL` (sémantique legacy :
     * expire immédiatement).
     *
     * @return array<int, SynapseWorkflow>
     */
    public function findExpiredEphemeral(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        /** @var array<int, SynapseWorkflow> $result */
        $result = $this->createQueryBuilder('w')
            ->andWhere('w.isEphemeral = true')
            ->andWhere('w.retentionUntil IS NULL OR w.retentionUntil < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @deprecated Utiliser {@see findEphemeral()}. Conservé comme alias.
     *
     * @return array<int, SynapseWorkflow>
     */
    public function findSandbox(): array
    {
        return $this->findEphemeral();
    }
}
