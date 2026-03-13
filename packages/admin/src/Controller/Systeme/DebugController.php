<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Controller\Systeme;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Journal de debug des échanges LLM — Administration Synapse.
 *
 * Affiche les traces d'appels LLM enregistrées en base (mode debug activé).
 * Permet aussi de vider les logs.
 */
#[Route('%synapse.admin_prefix%/systeme/debug', name: 'synapse_admin_')]
class DebugController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private SynapseDebugLogRepository $debugLogRepo,
        private SynapseConfigRepository $configRepo,
        private DatabaseConfigProvider $configProvider,
        private EntityManagerInterface $em,
        private PermissionCheckerInterface $permissionChecker,
        private TranslatorInterface $translator,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'debug', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $config = $this->configRepo->getGlobalConfig();

        if ($request->isMethod('POST') && $request->request->has('debug_mode_toggle')) {
            $this->validateCsrfToken($request, $this->csrfTokenManager, 'synapse_admin_debug_toggle');

            $config->setDebugMode($request->request->getBoolean('debug_mode'));
            $this->em->flush();
            $this->configProvider->clearCache();

            $statusKey = $config->isDebugMode() ? 'synapse.admin.health.status.enabled' : 'synapse.admin.health.status.disabled';
            $this->addFlash('success', $this->translator->trans('synapse.admin.debug.flash.mode_updated', ['%status%' => $this->translator->trans($statusKey, [], 'synapse_admin')], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_debug');
        }

        $logs = $this->debugLogRepo->findRecent(100);
        $total = $this->debugLogRepo->count([]);

        return $this->render('@Synapse/admin/systeme/debug.html.twig', [
            'logs' => $logs,
            'total' => $total,
            'config' => $config,
        ]);
    }

    #[Route('/clear', name: 'debug_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'debug_clear');

        $count = $this->debugLogRepo->clearAll();
        $this->addFlash('success', $this->translator->trans('synapse.admin.debug.flash.cleared', ['%count%' => $count], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_debug');
    }
}
