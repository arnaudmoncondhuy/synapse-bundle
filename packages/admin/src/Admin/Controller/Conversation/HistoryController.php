<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Conversation;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Historique global des conversations — Administration Synapse (accès Break-Glass)
 *
 * Cet accès est considéré Break-Glass : chaque consultation est auditée en log.
 * L'admin voit toutes les conversations de tous les utilisateurs.
 *
 * Utilise ConversationManager::getAllConversations() qui résout dynamiquement
 * la classe concrète (potentiellement étendue dans le projet hôte).
 */
#[Route('/synapse/admin/conversation/historique', name: 'synapse_admin_')]
class HistoryController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private ConversationManager $conversationManager,
        private PermissionCheckerInterface $permissionChecker,
        private ?LoggerInterface $logger = null,
    ) {}

    #[Route('', name: 'history', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $page  = max(1, (int) $request->query->get('page', '1'));
        $limit = 50;

        $conversations = $this->conversationManager->getAllConversations($limit, ($page - 1) * $limit);
        $total         = $this->conversationManager->countAllConversations();
        $pages         = (int) ceil($total / $limit);

        // Audit RGPD — chaque accès break-glass est tracé
        if ($this->logger) {
            $this->logger->warning('Break-Glass: Admin accessed full conversation history', [
                'admin_user' => $this->getUser()?->getUserIdentifier(),
                'page'       => $page,
                'ip'         => $request->getClientIp(),
            ]);
        }

        return $this->render('@Synapse/admin/conversation/history.html.twig', [
            'conversations' => $conversations,
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'limit'         => $limit,
        ]);
    }
}
