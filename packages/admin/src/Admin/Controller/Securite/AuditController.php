<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Securite;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Journal d'audit des accès administrateurs — Administration Synapse
 *
 * Centralise :
 * - Logs debug LLM (SynapseDebugLogRepository)
 * - Accès Break-Glass (tracés via PSR Logger dans HistoryController)
 */
#[Route('/synapse/admin/securite/audit', name: 'synapse_admin_')]
class AuditController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseDebugLogRepository $debugLogRepo,
        private PermissionCheckerInterface $permissionChecker,
    ) {}

    #[Route('', name: 'audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $tab  = $request->query->get('tab', 'debug');
        $logs = $this->debugLogRepo->findRecent(200);

        return $this->render('@Synapse/admin/securite/audit.html.twig', [
            'logs'  => $logs,
            'total' => $this->debugLogRepo->count([]),
            'tab'   => $tab,
        ]);
    }
}
