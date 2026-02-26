<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Usage;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseTokenUsageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Analytics — Statistiques d'usage LLM — Admin V2
 *
 * Expose les données de SynapseTokenUsageRepository pour visualiser
 * la consommation par période, module et modèle.
 */
#[Route('/synapse/admin-v2/usage/analytics', name: 'synapse_v2_admin_analytics')]
class AnalyticsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseTokenUsageRepository $tokenUsageRepo,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
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

        $periodLabel = match ($period) {
            7  => '7 derniers jours',
            90 => '3 derniers mois',
            default => '30 derniers jours',
        };

        return $this->render('@Synapse/admin_v2/usage/analytics.html.twig', [
            'period'            => $period,
            'period_label'      => $periodLabel,
            'stats'             => $globalStats,
            'daily_usage'       => $dailyUsage,
            'usage_by_module'   => $usageByModule,
            'usage_by_model'    => $usageByModel,
            'conversation_stats' => $usageByModule['chat'] ?? ['count' => 0, 'total_tokens' => 0],
        ]);
    }
}
