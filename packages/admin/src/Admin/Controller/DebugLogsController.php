<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Interface d'administration des journaux de debug
 *
 * Permet de consulter l'historique des appels LLM debuggés et tracés en base de données.
 */
#[Route('/synapse/admin/debug-logs')]
class DebugLogsController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseDebugLogRepository $debugLogRepo,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    /**
     * Liste les logs de debug récents
     */
    #[Route('', name: 'synapse_admin_debug_logs', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $debugLogs = $this->debugLogRepo->findRecent(100);

        return $this->render('@Synapse/admin/debug_logs.html.twig', [
            'logs' => $debugLogs,
        ]);
    }

    /**
     * Vide tous les logs de debug
     */
    #[Route('/clear', name: 'synapse_admin_debug_logs_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager);

        $count = $this->debugLogRepo->clearAll();

        $this->addFlash('success', sprintf('%d logs de debug ont été supprimés.', $count));

        return $this->redirectToRoute('synapse_admin_debug_logs');
    }
}
