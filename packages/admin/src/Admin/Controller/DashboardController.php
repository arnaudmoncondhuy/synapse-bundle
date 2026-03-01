<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard Administration Synapse — Vue d'ensemble Synapse
 *
 * Ce contrôleur est intentionnellement "mince" : il orchestre uniquement
 * des appels aux services/repositories du Core, sans logique métier propre.
 */
#[Route('/synapse/admin', name: 'synapse_admin_')]
class DashboardController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly SynapseLlmCallRepository $tokenUsageRepo,
        private readonly SynapseProviderRepository $providerRepo,
        private readonly SynapsePresetRepository $presetRepo,
        private readonly SynapseVectorMemoryRepository $vectorMemoryRepo,
    ) {}

    #[Route('', name: 'dashboard')]
    #[Route('/dashboard', name: 'dashboard_alt')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $now      = new \DateTimeImmutable();
        $last7d   = new \DateTimeImmutable('-7 days');
        $last30d  = new \DateTimeImmutable('-30 days');
        $last24h  = new \DateTimeImmutable('-24 hours');

        // KPIs token usage
        $tokenStats  = $this->tokenUsageRepo->getGlobalStats($last7d, $now);
        $dailyUsage  = $this->tokenUsageRepo->getDailyUsage($last30d, $now);
        $usageByModel = $this->tokenUsageRepo->getUsageByModel($last7d, $now);

        // Providers actifs
        $activeProviders = array_filter(
            $this->providerRepo->findAll(),
            fn($p) => $p->isEnabled() && $p->isConfigured()
        );

        // Preset actif
        $activePreset = $this->presetRepo->findActive();

        // Mémoire vectorielle
        $totalMemories = $this->vectorMemoryRepo->count([]);

        // Extraire les coûts par devise
        $costs = $tokenStats['costs'] ?? [];
        $costs_eur = $costs['EUR'] ?? 0;
        $costs_usd = $costs['USD'] ?? 0;

        return $this->render('@Synapse/admin/dashboard/index.html.twig', [
            'kpis' => [
                'tokens_7d'   => $tokenStats['total_tokens'] ?? 0,
                'costs_eur'   => $costs_eur,
                'costs_usd'   => $costs_usd,
                'total_memories'  => $totalMemories,
                'active_providers' => count($activeProviders),
            ],
            'daily_usage'     => $dailyUsage,
            'usage_by_model'  => $usageByModel,
            'active_providers' => $activeProviders,
            'active_preset'   => $activePreset,
        ]);
    }
}
