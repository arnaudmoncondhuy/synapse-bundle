<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\AdminV2\Controller\Securite;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard RGPD — Admin V2
 *
 * Centralise les informations liées à la conformité RGPD :
 * - Politique de rétention active
 * - Statistiques de données stockées
 * - Liens vers les actions de purge (conversation, logs debug)
 */
#[Route('/synapse/admin-v2/securite/rgpd', name: 'synapse_v2_admin_gdpr')]
class GdprController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private ConversationManager $conversationManager,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config           = $this->configRepo->getGlobalConfig();
        $totalConversations = $this->conversationManager->countAllConversations();

        return $this->render('@Synapse/admin_v2/securite/gdpr.html.twig', [
            'config'             => $config,
            'total_conversations' => $totalConversations,
        ]);
    }
}
