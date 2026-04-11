<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseWorkflowRun>
 */
class SynapseWorkflowRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseWorkflowRun::class);
    }

    /**
     * Retourne un run par son UUID logique (propagé dans `AgentContext::$workflowRunId`).
     */
    public function findByWorkflowRunId(string $workflowRunId): ?SynapseWorkflowRun
    {
        return $this->findOneBy(['workflowRunId' => $workflowRunId]);
    }

    /**
     * Récupère les métadonnées des runs récents d'un workflow — sans charger les
     * colonnes JSON `input`/`output` (grosses payloads). Même pattern que
     * {@see SynapseDebugLogRepository::findRoots()}.
     *
     * @return array<int, array{
     *     id: int,
     *     workflowRunId: string,
     *     workflowVersion: int,
     *     status: WorkflowRunStatus,
     *     currentStepIndex: int,
     *     stepsCount: int,
     *     startedAt: \DateTimeImmutable,
     *     completedAt: \DateTimeImmutable|null,
     *     userId: string|null,
     *     totalTokens: int|null,
     *     errorMessage: string|null,
     * }>
     */
    public function findRecentForWorkflow(SynapseWorkflow $workflow, int $limit = 50): array
    {
        /** @var array<int, array{id: int, workflowRunId: string, workflowVersion: int, status: WorkflowRunStatus, currentStepIndex: int, stepsCount: int, startedAt: \DateTimeImmutable, completedAt: \DateTimeImmutable|null, userId: string|null, totalTokens: int|null, errorMessage: string|null}> $result */
        $result = $this->createQueryBuilder('r')
            ->select('r.id', 'r.workflowRunId', 'r.workflowVersion', 'r.status', 'r.currentStepIndex', 'r.stepsCount', 'r.startedAt', 'r.completedAt', 'r.userId', 'r.totalTokens', 'r.errorMessage')
            ->andWhere('r.workflow = :workflow')
            ->setParameter('workflow', $workflow)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return $result;
    }

    /**
     * Compte les runs par statut pour un workflow donné (utilisé pour les KPIs admin).
     *
     * @return array<string, int> ex: ['completed' => 12, 'failed' => 3, 'running' => 1]
     */
    public function countByStatus(SynapseWorkflow $workflow): array
    {
        /** @var array<int, array{status: WorkflowRunStatus, total: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status', 'COUNT(r.id) AS total')
            ->andWhere('r.workflow = :workflow')
            ->setParameter('workflow', $workflow)
            ->groupBy('r.status')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof WorkflowRunStatus ? $row['status']->value : (string) $row['status'];
            $result[$status] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Supprime tous les runs dont le workflowKey est dans la liste fournie (cleanup MCP sandbox).
     *
     * @param string[] $workflowKeys
     *
     * @return int nombre de runs supprimés
     */
    public function deleteByWorkflowKeys(array $workflowKeys): int
    {
        if ([] === $workflowKeys) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.workflowKey IN (:keys)')
            ->setParameter('keys', $workflowKeys)
            ->getQuery()
            ->execute();
    }

    /**
     * Recherche filtrée des runs pour la page admin "Runs" (Chantier H).
     *
     * Tous les filtres sont optionnels. Chaque filtre est combiné en AND.
     *
     * @param array{
     *     status?: WorkflowRunStatus|null,
     *     workflowKey?: string|null,
     *     userId?: string|null,
     *     since?: \DateTimeImmutable|null,
     *     until?: \DateTimeImmutable|null,
     * } $filters
     *
     * @return SynapseWorkflowRun[]
     */
    public function findFiltered(array $filters = [], int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit);

        if (isset($filters['status']) && $filters['status'] instanceof WorkflowRunStatus) {
            $qb->andWhere('r.status = :status')->setParameter('status', $filters['status']);
        }
        if (isset($filters['workflowKey']) && is_string($filters['workflowKey']) && '' !== $filters['workflowKey']) {
            $qb->andWhere('r.workflowKey = :wkey')->setParameter('wkey', $filters['workflowKey']);
        }
        if (isset($filters['userId']) && is_string($filters['userId']) && '' !== $filters['userId']) {
            $qb->andWhere('r.userId = :uid')->setParameter('uid', $filters['userId']);
        }
        if (isset($filters['since']) && $filters['since'] instanceof \DateTimeImmutable) {
            $qb->andWhere('r.startedAt >= :since')->setParameter('since', $filters['since']);
        }
        if (isset($filters['until']) && $filters['until'] instanceof \DateTimeImmutable) {
            $qb->andWhere('r.startedAt <= :until')->setParameter('until', $filters['until']);
        }

        /** @var SynapseWorkflowRun[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Somme des coûts par jour sur les N derniers jours (Chantier H composante 5).
     *
     * Utilisé par le dashboard pour le graphe en barres "coût quotidien sur
     * 30 jours". Retourne une liste de tuples {date: 'YYYY-MM-DD', cost: float, runs: int}
     * triée par date ascendante. Les jours sans run sont **remplis à 0** pour
     * avoir un graphe continu (évite les trous dans l'axe des abscisses).
     *
     * Utilise SQL natif (pas DQL) parce que les fonctions de date varient
     * entre Postgres/MySQL/SQLite et que DQL demande un custom DQL function
     * pour `DATE()`. SQL natif est simple et ce repo est Postgres-only.
     *
     * @return array<int, array{date: string, cost: float, runs: int}>
     */
    public function costByDay(int $days = 30): array
    {
        $since = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days))->setTime(0, 0, 0);

        $connection = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
SELECT TO_CHAR(started_at::date, 'YYYY-MM-DD') AS day,
       COALESCE(SUM(total_cost), 0) AS cost,
       COUNT(id) AS runs
FROM synapse_workflow_run
WHERE started_at >= :since
GROUP BY day
ORDER BY day ASC
SQL;

        /** @var array<int, array{day: string, cost: string|float|null, runs: int|string}> $rows */
        $rows = $connection->executeQuery($sql, ['since' => $since->format('Y-m-d H:i:s')])->fetchAllAssociative();

        // Index rows by date for O(1) lookup
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['day']] = [
                'cost' => null !== $row['cost'] ? (float) $row['cost'] : 0.0,
                'runs' => (int) $row['runs'],
            ];
        }

        // Fill missing days with 0 for continuous timeline
        $result = [];
        $cursor = $since;
        $today = (new \DateTimeImmutable())->setTime(0, 0, 0);
        while ($cursor <= $today) {
            $dateStr = $cursor->format('Y-m-d');
            $result[] = [
                'date' => $dateStr,
                'cost' => $byDate[$dateStr]['cost'] ?? 0.0,
                'runs' => $byDate[$dateStr]['runs'] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $result;
    }

    /**
     * Top N workflows par coût total sur une période (Chantier H composante 5).
     *
     * @return array<int, array{workflowKey: string, cost: float, runs: int}>
     */
    public function topWorkflowsByCost(int $days = 30, int $limit = 10): array
    {
        $since = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days))->setTime(0, 0, 0);

        /** @var array<int, array{workflowKey: string, total: string|null, runs: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.workflowKey', 'SUM(r.totalCost) AS total', 'COUNT(r.id) AS runs')
            ->andWhere('r.startedAt >= :since')
            ->andWhere('r.totalCost IS NOT NULL')
            ->setParameter('since', $since)
            ->groupBy('r.workflowKey')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'workflowKey' => (string) $row['workflowKey'],
                'cost' => null !== ($row['total'] ?? null) ? (float) $row['total'] : 0.0,
                'runs' => (int) ($row['runs'] ?? 0),
            ];
        }

        return $result;
    }
}
