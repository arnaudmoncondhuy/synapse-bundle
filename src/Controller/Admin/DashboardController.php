<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Admin;

use ArnaudMoncondhuy\SynapseBundle\Repository\ConversationRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\MessageRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\TokenUsageRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard administrateur - Vue d'ensemble Synapse
 */
#[Route('/synapse/admin')]
class DashboardController extends AbstractController
{
    public function __construct(
        private ConversationRepository $conversationRepo,
        private MessageRepository $messageRepo,
        private TokenUsageRepository $tokenUsageRepo,
        private SynapseProviderRepository $providerRepo,
        private SynapseConfigRepository $configRepo,
    ) {
    }

    #[Route('', name: 'synapse_admin_index')]
    #[Route('/dashboard', name: 'synapse_admin_dashboard')]
    public function dashboard(): Response
    {
        // KPIs période 24h
        $last24h = new \DateTimeImmutable('-24 hours');
        $conversationsLast24h = $this->conversationRepo->countActiveLast24h();
        $activeUsersLast24h = $this->conversationRepo->countActiveUsersSince($last24h);

        // Usage tokens (7 derniers jours)
        $last7days = new \DateTimeImmutable('-7 days');
        $now = new \DateTimeImmutable();
        $tokenStats = $this->tokenUsageRepo->getGlobalStats($last7days, $now, [
            'default' => ['input' => 0.30, 'output' => 2.50], // Gemini 2.5 Flash
        ]);

        // Usage quotidien (30 derniers jours pour le graphique)
        $last30days = new \DateTimeImmutable('-30 days');
        $dailyUsage = $this->tokenUsageRepo->getDailyUsage($last30days, $now);

        // Active providers (enabled and configured)
        $activeProviders = array_filter($this->providerRepo->findAll(), function($provider) {
            return $provider->isEnabled() && $provider->isConfigured();
        });

        // Active preset for default scope
        $activePreset = $this->configRepo->findOneBy(['scope' => 'default']);

        return $this->render('@Synapse/admin/dashboard.html.twig', [
            'kpis' => [
                'active_conversations' => $conversationsLast24h,
                'active_users_24h' => $activeUsersLast24h,
                'tokens_7d' => $tokenStats['totalTokens'] ?? 0,
                'tokens_cost' => $tokenStats['cost'] ?? 0,
            ],
            'daily_usage' => $dailyUsage,
            'active_providers' => $activeProviders,
            'active_preset' => $activePreset,
        ]);
    }
}
