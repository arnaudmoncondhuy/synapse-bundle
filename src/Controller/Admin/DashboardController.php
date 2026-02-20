<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Admin;

use ArnaudMoncondhuy\SynapseBundle\Repository\ConversationRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\MessageRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\TokenUsageRepository;
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

        // Risques en attente
        $pendingRisks = $this->conversationRepo->countPendingRisks();

        // Usage tokens (7 derniers jours)
        $last7days = new \DateTimeImmutable('-7 days');
        $now = new \DateTimeImmutable();
        $tokenStats = $this->tokenUsageRepo->getGlobalStats($last7days, $now, [
            'default' => ['input' => 0.30, 'output' => 2.50], // Gemini 2.5 Flash
        ]);

        // Usage quotidien (30 derniers jours pour le graphique)
        $last30days = new \DateTimeImmutable('-30 days');
        $dailyUsage = $this->tokenUsageRepo->getDailyUsage($last30days, $now);

        return $this->render('@Synapse/admin/dashboard.html.twig', [
            'kpis' => [
                'active_conversations' => $conversationsLast24h,
                'active_users_24h' => $activeUsersLast24h,
                'risks_pending' => $pendingRisks,
                'tokens_cost' => $tokenStats['cost'] ?? 0,
            ],
            'daily_usage' => $dailyUsage,
        ]);
    }
}
