<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMessageRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseTokenUsageRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseVectorMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Dashboard administrateur - Vue d'ensemble Synapse
 */
#[Route('/synapse/admin')]
class DashboardController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private SynapseTokenUsageRepository $tokenUsageRepo,
        private SynapseProviderRepository $providerRepo,
        private SynapsePresetRepository $presetRepo,
        private SynapseVectorMemoryRepository $vectorMemoryRepo,
        private PermissionCheckerInterface $permissionChecker,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(param: 'synapse.persistence.conversation_class')]
        private ?string $conversationClass = null,
    ) {}

    #[Route('', name: 'synapse_admin_index')]
    #[Route('/dashboard', name: 'synapse_admin_dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        // KPI : Conversations
        $repoClass = $this->conversationClass ?? SynapseConversation::class;
        $conversationRepo = $this->em->getRepository($repoClass);
        $last24h = new \DateTimeImmutable('-24 hours');

        // Check if repository supports the custom methods (in case persistence is disabled/abstract)
        $conversationsLast24h = method_exists($conversationRepo, 'countActiveLast24h') ? $conversationRepo->countActiveLast24h() : 0;
        $activeUsersLast24h = method_exists($conversationRepo, 'countActiveUsersSince') ? $conversationRepo->countActiveUsersSince($last24h) : 0;

        // Usage tokens (7 derniers jours)
        $last7days = new \DateTimeImmutable('-7 days');
        $now = new \DateTimeImmutable();
        $tokenStats = $this->tokenUsageRepo->getGlobalStats($last7days, $now);

        // Usage quotidien (30 derniers jours pour le graphique)
        $last30days = new \DateTimeImmutable('-30 days');
        $dailyUsage = $this->tokenUsageRepo->getDailyUsage($last30days, $now);

        // Active providers (enabled and configured)
        $activeProviders = array_filter($this->providerRepo->findAll(), function ($provider) {
            return $provider->isEnabled() && $provider->isConfigured();
        });

        // Active preset
        $activePreset = $this->presetRepo->findActive();

        // Nombre total de souvenirs mémorisés
        $totalMemories = $this->vectorMemoryRepo->count([]);

        return $this->render('@Synapse/admin/dashboard.html.twig', [
            'kpis' => [
                'active_conversations' => $conversationsLast24h,
                'active_users_24h'     => $activeUsersLast24h,
                'tokens_7d'            => $tokenStats['total_tokens'] ?? 0,
                'tokens_cost'          => $tokenStats['cost'] ?? 0,
                'total_memories'       => $totalMemories,
            ],
            'daily_usage'      => $dailyUsage,
            'active_providers' => $activeProviders,
            'active_preset'    => $activePreset,
        ]);
    }
}
