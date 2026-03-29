<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Usage;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Analytics — Statistiques d'usage LLM — Administration Synapse.
 *
 * Expose les données de SynapseLlmCallRepository pour visualiser
 * la consommation par période, module et modèle.
 */
#[Route('%synapse.admin_prefix%/usage', name: 'synapse_admin_')]
class AnalyticsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseLlmCallRepository $tokenUsageRepo,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    #[Route('/analytics', name: 'analytics', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $period = (int) $request->query->get('period', '30');
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;

        $start = new \DateTimeImmutable("-{$period} days");
        $end = new \DateTimeImmutable();

        $globalStats = $this->tokenUsageRepo->getGlobalStats($start, $end);
        $dailyUsage = $this->fillMissingDays($this->tokenUsageRepo->getDailyUsage($start, $end), $start, $end);
        $usageByModule = $this->tokenUsageRepo->getUsageByModule($start, $end);
        $usageByModel = $this->tokenUsageRepo->getUsageByModel($start, $end);

        // Extraire les coûts par devise
        $costs = $globalStats['costs'] ?? [];
        $costs_eur = $costs['EUR'] ?? 0;
        $costs_usd = $costs['USD'] ?? 0;

        $periodLabel = match ($period) {
            7 => '7 derniers jours',
            90 => '3 derniers mois',
            default => '30 derniers jours',
        };

        return $this->render('@Synapse/admin/usage/analytics.html.twig', [
            'period' => $period,
            'period_label' => $periodLabel,
            'stats' => $globalStats,
            'costs_eur' => $costs_eur,
            'costs_usd' => $costs_usd,
            'daily_usage' => $dailyUsage,
            'usage_by_module' => $usageByModule,
            'usage_by_model' => $usageByModel,
            'conversation_stats' => $usageByModule['chat'] ?? ['count' => 0, 'total_tokens' => 0],
        ]);
    }

    /**
     * Complète le tableau daily_usage avec des entrées à zéro pour les jours sans donnée.
     *
     * @param array<string, array<string, mixed>> $data
     *
     * @return array<string, array{date: string, total_tokens: int, prompt_tokens: int, completion_tokens: int, thinking_tokens: int}>
     */
    private function fillMissingDays(array $data, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $filled = [];
        $cursor = $start;
        $endDate = $end->setTime(0, 0, 0);

        while ($cursor <= $endDate) {
            $key = $cursor->format('Y-m-d');
            $row = $data[$key] ?? [];

            $filled[$key] = [
                'date' => $key,
                'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                'prompt_tokens' => (int) ($row['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
                'thinking_tokens' => (int) ($row['thinking_tokens'] ?? 0),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $filled;
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        return $this->render('@Synapse/admin/shared/placeholder.html.twig', [
            'page_title' => 'Export',
            'icon' => 'download',
            'breadcrumb_section' => 'Usage',
            'coming_soon_message' => 'Exportez vos données d\'usage au format CSV ou JSON pour vos rapports et analyses externes.',
        ]);
    }
}
