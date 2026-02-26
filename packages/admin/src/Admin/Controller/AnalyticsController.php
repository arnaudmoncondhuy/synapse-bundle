<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseTokenUsageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Analytics - Statistiques d'usage détaillées
 */
#[Route('/synapse/admin/analytics')]
class AnalyticsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseTokenUsageRepository $tokenUsageRepo,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    /**
     * Vue analytics avec graphiques
     */
    #[Route('', name: 'synapse_admin_analytics')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $period = $request->query->get('period', '30'); // 7, 30, 90 jours
        $days = (int) $period;

        $start = new \DateTimeImmutable("-{$days} days");
        $end = new \DateTimeImmutable();

        // Stats globales
        $globalStats = $this->tokenUsageRepo->getGlobalStats($start, $end);

        // Usage quotidien (pour graphique)
        $dailyUsage = $this->tokenUsageRepo->getDailyUsage($start, $end);

        // Usage par module
        $usageByModule = $this->tokenUsageRepo->getUsageByModule($start, $end);

        // Usage par modèle
        $usageByModel = $this->tokenUsageRepo->getUsageByModel($start, $end);

        // Tâches automatisées
        $automatedTasks = $this->tokenUsageRepo->getAutomatedTaskStats($start, $end);

        return $this->render('@Synapse/admin/analytics.html.twig', [
            'period' => $days,
            'period_label' => $days === 7 ? '7 derniers jours' : ($days === 90 ? '3 derniers mois' : '30 derniers jours'),
            'cost' => $globalStats['cost'] ?? 0,
            'stats' => $globalStats,
            'daily' => $dailyUsage,
            'usage_by_module' => $usageByModule,
            'usage_by_model' => $usageByModel,
            'automated_stats' => $automatedTasks,
            'conversation_stats' => $usageByModule['chat'] ?? ['count' => 0, 'total_tokens' => 0],
            'embedding_stats' => $usageByModule['embedding'] ?? ['count' => 0, 'total_tokens' => 0],
        ]);
    }
}
