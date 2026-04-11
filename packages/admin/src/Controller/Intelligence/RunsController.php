<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Intelligence;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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
        private readonly SynapseDebugLogRepository $debugLogRepository,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowRunner $workflowRunner,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
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

    /**
     * Chantier H phase 2 : détail d'un run avec timeline visuelle.
     *
     * Reconstitue la timeline d'un workflow run à partir de la définition du
     * workflow, de l'état courant du run (currentStepIndex, status), et des
     * debug logs rattachés (filtrés par workflow_run_id, triés chronologiquement).
     *
     * Les steps individuels ne sont pas persistés comme entités à part dans
     * Synapse — seule la définition du workflow permet de connaître la
     * structure complète. La durée, les tokens et le coût de chaque step
     * sont reconstruits à partir des SynapseDebugLog correspondants.
     */
    #[Route('/{workflowRunId}', name: 'runs_detail', methods: ['GET'], requirements: ['workflowRunId' => '[0-9a-f-]{36}'])]
    public function detail(string $workflowRunId): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $run = $this->runRepository->findByWorkflowRunId($workflowRunId);
        if (null === $run) {
            throw new NotFoundHttpException(sprintf('Workflow run "%s" not found.', $workflowRunId));
        }

        // Résoudre la définition du workflow (relation directe ou fallback par
        // workflowKey si la relation a été nullifiée par onDelete SET NULL)
        $workflow = $run->getWorkflow();
        if (null === $workflow) {
            $workflow = $this->workflowRepository->findOneBy(['workflowKey' => $run->getWorkflowKey()]);
        }

        // Récupère les debug logs attachés au run, triés chronologiquement
        /** @var SynapseDebugLog[] $debugLogs */
        $debugLogs = $this->debugLogRepository->findBy(
            ['workflowRunId' => $workflowRunId],
            ['createdAt' => 'ASC'],
        );

        // Construire la timeline des steps
        $stepsTimeline = $this->buildStepsTimeline($run, $workflow, $debugLogs);

        // Résumé (redondant avec le run, mais plus pratique pour le template)
        $summary = [
            'total_duration_s' => $run->getDurationSeconds(),
            'total_tokens' => $run->getTotalTokens() ?? 0,
            'total_cost' => $run->getTotalCost() ?? 0.0,
            'steps_count' => $run->getStepsCount(),
            'current_step_index' => $run->getCurrentStepIndex(),
        ];

        return $this->render('@Synapse/admin/intelligence/run_detail.html.twig', [
            'run' => $run,
            'workflow' => $workflow,
            'steps_timeline' => $stepsTimeline,
            'summary' => $summary,
            'debug_logs_count' => count($debugLogs),
            'is_running' => !$run->getStatus()->isTerminal(),
        ]);
    }

    /**
     * Construit le tableau de la timeline pour le template.
     *
     * Pour chaque step déclaré dans la définition du workflow, dérive son
     * statut depuis (run.currentStepIndex, run.status) et agrège les metrics
     * des debug logs rattachés via une heuristique sur `action` et sur
     * l'ordre chronologique.
     *
     * Heuristique de rattachement step ↔ debug_log : les debug logs n'ont
     * pas de champ `step_index` explicite, on les associe par l'ordre
     * chronologique de leur `createdAt` et la valeur `agent_run_id` qui
     * correspond au step (un agent invoqué par MultiAgent a un requestId
     * dédié par step grâce à createChild()). Les logs qui appartiennent
     * à un step `N` sont ceux créés entre le SynapseWorkflowStepStartedEvent
     * du step N et celui du step N+1 (ou la fin du run).
     *
     * Version simplifiée phase 2 : on distribue les debug logs linéairement
     * sur les steps en utilisant leur ordre chronologique uniquement, sans
     * tenir compte des agent_run_id. Suffisant pour 95% des cas (1 step =
     * 1 agent call = 1 debug log) et évite de bricoler sur la reconstruction
     * d'arbre. Si un step fait du tool-calling multi-tour, plusieurs debug
     * logs peuvent lui être attribués.
     *
     * @param SynapseDebugLog[] $debugLogs
     *
     * @return array<int, array{
     *     index: int,
     *     name: string,
     *     agent_name: string,
     *     status: string,
     *     tokens: int,
     *     cost: float,
     *     debug_ids: string[],
     *     model: ?string,
     * }>
     */
    private function buildStepsTimeline(SynapseWorkflowRun $run, ?SynapseWorkflow $workflow, array $debugLogs): array
    {
        $definition = null !== $workflow ? $workflow->getDefinition() : null;
        $rawSteps = is_array($definition) && isset($definition['steps']) && is_array($definition['steps'])
            ? $definition['steps']
            : [];

        // Si la définition est introuvable (workflow supprimé, definition vide),
        // on ne peut pas reconstituer les noms de steps — on crée des placeholders
        // à partir de stepsCount.
        if ([] === $rawSteps && $run->getStepsCount() > 0) {
            for ($i = 0; $i < $run->getStepsCount(); ++$i) {
                $rawSteps[] = ['name' => sprintf('step_%d', $i), 'agent_name' => '(inconnu)'];
            }
        }

        $currentIndex = $run->getCurrentStepIndex();
        $runStatus = $run->getStatus();
        $timeline = [];

        // Distribution linéaire des debug logs sur les steps
        // Si on a moins de logs que de steps terminés, on met 0/null pour les manquants
        $completedCount = min($currentIndex, count($rawSteps));
        $logsPerStep = $completedCount > 0 ? (int) floor(count($debugLogs) / max(1, $completedCount)) : 0;
        $logCursor = 0;

        foreach ($rawSteps as $index => $rawStep) {
            $name = is_string($rawStep['name'] ?? null) ? $rawStep['name'] : sprintf('step_%d', $index);
            $agentName = is_string($rawStep['agent_name'] ?? null) ? $rawStep['agent_name'] : '(inconnu)';

            // Statut dérivé
            $stepStatus = 'pending';
            if ($index < $currentIndex) {
                $stepStatus = 'completed';
            } elseif ($index === $currentIndex) {
                if (WorkflowRunStatus::RUNNING === $runStatus) {
                    $stepStatus = 'running';
                } elseif (WorkflowRunStatus::FAILED === $runStatus) {
                    $stepStatus = 'failed';
                } elseif (WorkflowRunStatus::COMPLETED === $runStatus) {
                    $stepStatus = 'completed';
                } elseif (WorkflowRunStatus::CANCELLED === $runStatus) {
                    $stepStatus = 'cancelled';
                }
            }

            // Agrège les logs rattachés à ce step (heuristique linéaire)
            $stepTokens = 0;
            $stepCost = 0.0;
            $stepDebugIds = [];
            $stepModel = null;

            if ('completed' === $stepStatus && $logsPerStep > 0) {
                $endCursor = min($logCursor + $logsPerStep, count($debugLogs));
                // Pour le dernier step completed, on colle jusqu'à la fin pour ne pas perdre des logs
                if ($index === $completedCount - 1) {
                    $endCursor = count($debugLogs);
                }
                for ($i = $logCursor; $i < $endCursor; ++$i) {
                    $log = $debugLogs[$i];
                    $stepTokens += $log->getTotalTokens() ?? 0;
                    $stepCost += $log->getCost() ?? 0.0;
                    $stepDebugIds[] = $log->getDebugId();
                    if (null === $stepModel && null !== $log->getModel()) {
                        $stepModel = $log->getModel();
                    }
                }
                $logCursor = $endCursor;
            }

            $timeline[] = [
                'index' => $index,
                'name' => $name,
                'agent_name' => $agentName,
                'status' => $stepStatus,
                'tokens' => $stepTokens,
                'cost' => $stepCost,
                'debug_ids' => $stepDebugIds,
                'model' => $stepModel,
            ];
        }

        return $timeline;
    }

    /**
     * Chantier H phase 4 : replay d'un step avec son input snapshot.
     *
     * Crée un workflow éphémère à 1 step (celui à rejouer), l'exécute avec
     * l'input résolu capturé lors du run original, et redirige vers le
     * detail du nouveau run. Le nouveau workflow est marqué éphémère avec
     * retention 7j pour être nettoyé automatiquement.
     *
     * Protégé par CSRF parce que c'est une action mutative (crée un run,
     * consomme des tokens, coûte de l'argent).
     */
    #[Route('/{workflowRunId}/replay-step/{stepIndex}', name: 'runs_replay_step', methods: ['POST'], requirements: ['workflowRunId' => '[0-9a-f-]{36}', 'stepIndex' => '\d+'])]
    public function replayStep(string $workflowRunId, int $stepIndex, Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, sprintf('replay_step_%s_%d', $workflowRunId, $stepIndex));

        $originalRun = $this->runRepository->findByWorkflowRunId($workflowRunId);
        if (null === $originalRun) {
            throw new NotFoundHttpException(sprintf('Workflow run "%s" not found.', $workflowRunId));
        }

        // Récupérer la définition du workflow original
        $originalWorkflow = $originalRun->getWorkflow()
            ?? $this->workflowRepository->findOneBy(['workflowKey' => $originalRun->getWorkflowKey()]);

        if (null === $originalWorkflow) {
            throw new NotFoundHttpException(sprintf('Workflow definition for run "%s" is no longer available.', $workflowRunId));
        }

        $definition = $originalWorkflow->getDefinition();
        $steps = is_array($definition['steps'] ?? null) ? $definition['steps'] : [];
        if (!isset($steps[$stepIndex]) || !is_array($steps[$stepIndex])) {
            throw new BadRequestHttpException(sprintf('Step index %d does not exist in workflow "%s".', $stepIndex, $originalWorkflow->getWorkflowKey()));
        }

        $targetStep = $steps[$stepIndex];
        $stepName = is_string($targetStep['name'] ?? null) ? $targetStep['name'] : sprintf('step_%d', $stepIndex);

        // Récupérer l'input capturé pour ce step
        $stepInputs = $originalRun->getStepInputs() ?? [];
        if (!isset($stepInputs[$stepName]) || !is_array($stepInputs[$stepName])) {
            $this->addFlash('error', sprintf(
                'Input non capturé pour le step "%s". Le step a probablement été exécuté avant le Chantier H4 (2026-04-11). Les runs postérieurs capturent les inputs automatiquement.',
                $stepName,
            ));

            return $this->redirectToRoute('synapse_admin_runs_detail', ['workflowRunId' => $workflowRunId]);
        }

        $capturedInput = $stepInputs[$stepName];

        // Construire un workflow éphémère à 1 seul step qui utilise un
        // input_mapping par identité (chaque clé de l'input capturé devient
        // une clé JSONPath `$.inputs.X`). Pas de transformation, rejouer à
        // l'identique.
        $replayDefinition = [
            'version' => 1,
            'steps' => [
                [
                    'name' => $stepName,
                    'agent_name' => (string) ($targetStep['agent_name'] ?? '(unknown)'),
                    'input_mapping' => array_map(
                        static fn (string $key): string => sprintf('$.inputs.%s', $key),
                        array_combine(array_keys($capturedInput), array_keys($capturedInput)),
                    ),
                    'output_key' => 'replay_output',
                ],
            ],
            'outputs' => [
                'replay_output' => sprintf('$.steps.%s.output.text', $stepName),
            ],
        ];

        $replayWorkflow = new SynapseWorkflow();
        $replayWorkflow->setWorkflowKey(sprintf('replay_%s_step%d_%d', substr($workflowRunId, 0, 8), $stepIndex, time()));
        $replayWorkflow->setName(sprintf('Replay step "%s" de %s', $stepName, $originalWorkflow->getName()));
        $replayWorkflow->setDescription(sprintf(
            'Replay éphémère du step %d ("%s") du run %s (workflow %s)',
            $stepIndex,
            $stepName,
            substr($workflowRunId, 0, 8),
            $originalWorkflow->getWorkflowKey(),
        ));
        $replayWorkflow->setDefinition($replayDefinition);
        $replayWorkflow->setIsBuiltin(false);
        $replayWorkflow->setIsActive(true);
        $replayWorkflow->setIsEphemeral(true);
        $replayWorkflow->setRetentionUntil((new \DateTimeImmutable())->modify('+7 days'));

        $this->entityManager->persist($replayWorkflow);
        $this->entityManager->flush();

        try {
            $output = $this->workflowRunner->run(
                $replayWorkflow,
                Input::ofStructured($capturedInput),
                [],
            );
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Replay du step "%s" échoué : %s', $stepName, $e->getMessage()));

            return $this->redirectToRoute('synapse_admin_runs_detail', ['workflowRunId' => $workflowRunId]);
        }

        $newRunId = $output->getMetadata()['workflow_run_id'] ?? null;
        if (!is_string($newRunId)) {
            $this->addFlash('warning', 'Replay terminé mais runId du nouveau run introuvable. Voir la liste des runs.');

            return $this->redirectToRoute('synapse_admin_runs');
        }

        $this->addFlash('success', sprintf(
            'Replay du step "%s" terminé avec succès. Nouveau run : %s.',
            $stepName,
            substr($newRunId, 0, 8),
        ));

        return $this->redirectToRoute('synapse_admin_runs_detail', ['workflowRunId' => $newRunId]);
    }
}
