<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vue admin "Runs" — Chantier H composante 1 + 5.
 *
 * Liste filtrable de tous les SynapseWorkflowRun (persistants + éphémères),
 * avec filtres combinables (statut, workflow, utilisateur, période), et un
 * widget graphe des coûts 30 jours en en-tête.
 *
 * Cette page est **la** porte d'entrée pour comprendre ce qui se passe
 * dans Synapse côté coûts et exécution. Elle valorise directement :
 * - Chantier A (éphémères visibles)
 * - Chantier B (coûts persistés)
 * - Chantier D (plans exécutés)
 * - Chantier G (runs async)
 *
 * Out of scope (phases H2-H4 à venir après review) :
 * - Timeline visuel par run (Mermaid)
 * - Streaming live des runs en cours (NDJSON)
 * - Replay d'un step
 */
#[Route('%synapse.admin_prefix%/intelligence/runs', name: 'synapse_admin_')]
class RunsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseWorkflowRunRepository $runRepository,
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    #[Route('', name: 'runs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        // ── Filtres depuis query string ──────────────────────────────────────
        $filters = [];

        $statusRaw = $request->query->get('status');
        if (is_string($statusRaw) && '' !== $statusRaw) {
            $status = WorkflowRunStatus::tryFrom($statusRaw);
            if (null !== $status) {
                $filters['status'] = $status;
            }
        }

        $workflowKeyRaw = $request->query->get('workflow_key');
        if (is_string($workflowKeyRaw) && '' !== $workflowKeyRaw) {
            $filters['workflowKey'] = $workflowKeyRaw;
        }

        $userIdRaw = $request->query->get('user_id');
        if (is_string($userIdRaw) && '' !== $userIdRaw) {
            $filters['userId'] = $userIdRaw;
        }

        // Période : 24h / 7j / 30j / all
        $periodRaw = $request->query->get('period', '7d');
        $period = is_string($periodRaw) ? $periodRaw : '7d';
        $sinceDate = match ($period) {
            '24h' => new \DateTimeImmutable('-1 day'),
            '7d' => new \DateTimeImmutable('-7 days'),
            '30d' => new \DateTimeImmutable('-30 days'),
            'all' => null,
            default => new \DateTimeImmutable('-7 days'),
        };
        if (null !== $sinceDate) {
            $filters['since'] = $sinceDate;
        }

        // ── Query ────────────────────────────────────────────────────────────
        $runs = $this->runRepository->findFiltered($filters, 200);

        // ── Data pour les filtres (dropdowns) ────────────────────────────────
        // Liste des workflows persistants + éphémères, déduplications par key
        $allWorkflows = array_merge(
            $this->workflowRepository->findAllOrdered(),
            $this->workflowRepository->findEphemeral(),
        );
        $workflowOptions = [];
        foreach ($allWorkflows as $wf) {
            $workflowOptions[$wf->getWorkflowKey()] = $wf->getName();
        }
        ksort($workflowOptions);

        // Liste des users uniques dans les runs filtrés (pour le dropdown user)
        $userIds = [];
        foreach ($runs as $run) {
            $uid = $run->getUserId();
            if (null !== $uid && '' !== $uid) {
                $userIds[$uid] = true;
            }
        }
        ksort($userIds);

        // ── Cost chart data (Chantier H composante 5) ────────────────────────
        $costChartDays = match ($period) {
            '24h' => 1,
            '7d' => 7,
            '30d' => 30,
            'all' => 30, // cap à 30j pour le graphe même en mode "all"
            default => 7,
        };
        $costByDay = $this->runRepository->costByDay($costChartDays);
        $topWorkflowsByCost = $this->runRepository->topWorkflowsByCost($costChartDays, 5);

        // Totaux pour les KPIs en-tête
        $totalCost = array_sum(array_column($costByDay, 'cost'));
        $totalRunsInPeriod = array_sum(array_column($costByDay, 'runs'));

        return $this->render('@Synapse/admin/intelligence/runs.html.twig', [
            'runs' => $runs,
            'filters' => [
                'status' => $filters['status'] ?? null,
                'workflow_key' => $filters['workflowKey'] ?? null,
                'user_id' => $filters['userId'] ?? null,
                'period' => $period,
            ],
            'workflow_options' => $workflowOptions,
            'user_options' => array_keys($userIds),
            'status_options' => [
                WorkflowRunStatus::PENDING,
                WorkflowRunStatus::RUNNING,
                WorkflowRunStatus::COMPLETED,
                WorkflowRunStatus::FAILED,
                WorkflowRunStatus::CANCELLED,
            ],
            'cost_by_day' => $costByDay,
            'top_workflows_by_cost' => $topWorkflowsByCost,
            'total_cost_period' => $totalCost,
            'total_runs_period' => $totalRunsInPeriod,
        ]);
    }
}
