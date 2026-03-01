<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller\Systeme;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Journal de debug des échanges LLM — Administration Synapse
 *
 * Affiche les traces d'appels LLM enregistrées en base (mode debug activé).
 * Permet aussi de vider les logs.
 */
#[Route('/synapse/admin/systeme/debug', name: 'synapse_admin_')]
class DebugController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseDebugLogRepository $debugLogRepo,
        private PermissionCheckerInterface $permissionChecker,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

    #[Route('', name: 'debug', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $logs  = $this->debugLogRepo->findRecent(100);
        $total = $this->debugLogRepo->count([]);

        return $this->render('@Synapse/admin/systeme/debug.html.twig', [
            'logs'  => $logs,
            'total' => $total,
        ]);
    }

    #[Route('/clear', name: 'debug_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'debug_clear');

        $count = $this->debugLogRepo->clearAll();
        $this->addFlash('success', sprintf('%d logs de debug supprimés.', $count));

        return $this->redirectToRoute('synapse_admin_debug');
    }
}
