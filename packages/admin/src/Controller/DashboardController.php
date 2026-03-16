<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitLogRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard Administration Synapse — Vue d'ensemble de l'état du système.
 */
#[Route('%synapse.admin_prefix%', name: 'synapse_admin_')]
class DashboardController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly SynapseLlmCallRepository $tokenUsageRepo,
        private readonly SynapseProviderRepository $providerRepo,
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly SynapseVectorMemoryRepository $vectorMemoryRepo,
        private readonly SynapseAgentRepository $agentRepo,
        private readonly SynapseSpendingLimitLogRepository $spendingAlertRepo,
        private readonly ToolRegistry $toolRegistry,
    ) {
    }

    #[Route('', name: 'dashboard')]
    #[Route('/dashboard', name: 'dashboard_alt')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $now = new \DateTimeImmutable();
        $last7d = new \DateTimeImmutable('-7 days');
        $last24h = new \DateTimeImmutable('-24 hours');

        // ── État du système ───────────────────────────────────────────────────
        $allProviders = $this->providerRepo->findAll();
        $activeProviders = array_values(array_filter($allProviders, fn ($p) => $p->isEnabled() && $p->isConfigured()));

        try {
            $activePreset = $this->presetRepo->findActive();
        } catch (\Doctrine\ORM\NoResultException) {
            $activePreset = null;
        }

        // ── Alertes budget (7 derniers jours) ─────────────────────────────────
        $recentAlerts = $this->spendingAlertRepo->findByPeriod(
            new \DateTimeImmutable('-7 days'),
            $now
        );

        // ── Activité (7 jours) ────────────────────────────────────────────────
        $tokenStats = $this->tokenUsageRepo->getGlobalStats($last7d, $now);
        $costs = $tokenStats['costs'] ?? [];

        // ── Inventaire ────────────────────────────────────────────────────────
        $agents = $this->agentRepo->findAllOrdered();
        $tools = $this->toolRegistry->getTools();
        $presets = $this->presetRepo->findAllPresets();

        return $this->render('@Synapse/admin/dashboard/index.html.twig', [
            // État du système
            'system' => [
                'providers_total' => count($allProviders),
                'providers_active' => count($activeProviders),
                'providers_list' => $activeProviders,
                'preset' => $activePreset,
                'alerts_count' => count($recentAlerts),
                'is_healthy' => count($activeProviders) > 0 && null !== $activePreset,
            ],
            // Activité 7 jours
            'activity' => [
                'tokens' => $tokenStats['total_tokens'] ?? 0,
                'requests' => $tokenStats['request_count'] ?? 0,
                'costs_eur' => $costs['EUR'] ?? 0.0,
                'costs_usd' => $costs['USD'] ?? 0.0,
            ],
            // Inventaire
            'inventory' => [
                'agents_total' => count($agents),
                'agents_custom' => count(array_filter($agents, fn ($a) => !$a->isBuiltin())),
                'tools_total' => count($tools),
                'presets_total' => count($presets),
                'memories_total' => $this->vectorMemoryRepo->count([]),
            ],
        ]);
    }
}
