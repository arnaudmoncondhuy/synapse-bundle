<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Usage;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Analytics — Statistiques d'usage LLM — Administration Synapse
 *
 * Expose les données de SynapseLlmCallRepository pour visualiser
 * la consommation par période, module et modèle.
 */
#[Route('/synapse/admin/usage', name: 'synapse_admin_')]
class AnalyticsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseLlmCallRepository $tokenUsageRepo,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('/analytics', name: 'analytics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $period = (int) $request->query->get('period', '30');
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;

        $start = new \DateTimeImmutable("-{$period} days");
        $end   = new \DateTimeImmutable();

        $globalStats   = $this->tokenUsageRepo->getGlobalStats($start, $end);
        $dailyUsage    = $this->tokenUsageRepo->getDailyUsage($start, $end);
        $usageByModule = $this->tokenUsageRepo->getUsageByModule($start, $end);
        $usageByModel  = $this->tokenUsageRepo->getUsageByModel($start, $end);

        // Extraire les coûts par devise
        $costs = $globalStats['costs'] ?? [];
        $costs_eur = $costs['EUR'] ?? 0;
        $costs_usd = $costs['USD'] ?? 0;

        $periodLabel = match ($period) {
            7  => '7 derniers jours',
            90 => '3 derniers mois',
            default => '30 derniers jours',
        };

        return $this->render('@Synapse/admin/usage/analytics.html.twig', [
            'period'            => $period,
            'period_label'      => $periodLabel,
            'stats'             => $globalStats,
            'costs_eur'         => $costs_eur,
            'costs_usd'         => $costs_usd,
            'daily_usage'       => $dailyUsage,
            'usage_by_module'   => $usageByModule,
            'usage_by_model'    => $usageByModel,
            'conversation_stats' => $usageByModule['chat'] ?? ['count' => 0, 'total_tokens' => 0],
        ]);
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        return $this->render('@Synapse/admin/shared/placeholder.html.twig', [
            'page_title'           => 'Export',
            'icon'                 => 'download',
            'breadcrumb_section'   => 'Usage',
            'coming_soon_message'  => 'Exportez vos données d\'usage au format CSV ou JSON pour vos rapports et analyses externes.',
        ]);
    }
}
