<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Securite;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Dashboard RGPD — Administration Synapse.
 *
 * Centralise les informations liées à la conformité RGPD :
 * - Politique de rétention active
 * - Statistiques de données stockées
 * - Liens vers les actions de purge (conversation, logs debug)
 */
#[Route('%synapse.admin_prefix%/securite/rgpd', name: 'synapse_admin_')]
class GdprController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private readonly SynapseConfigRepository $configRepo,
        private readonly ConversationManager $conversationManager,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly EntityManagerInterface $em,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'gdpr', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();

        if ($request->isMethod('POST')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_admin_gdpr');

            // Rétention RGPD (1 jour → 10 ans)
            $retentionDays = (int) ($request->request->get('retention_days') ?? 30);
            if ($retentionDays >= 1 && $retentionDays <= 3650) {
                $config->setRetentionDays($retentionDays);
                $this->em->flush();
                $this->addFlash('success', 'La durée de rétention a été mise à jour.');
            }

            return $this->redirectToRoute('synapse_admin_gdpr');
        }

        $totalConversations = $this->conversationManager->countAllConversations();

        return $this->render('@Synapse/admin/securite/gdpr.html.twig', [
            'config' => $config,
            'total_conversations' => $totalConversations,
        ]);
    }
}
