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
        private readonly SynapseDebugLogRepository $debugLogRepo,
        private readonly SynapseConfigRepository $configRepo,
        private readonly DatabaseConfigProvider $configProvider,
        private readonly EntityManagerInterface $em,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly TranslatorInterface $translator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
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
            $this->addFlash('success', $this->translator->trans('synapse.admin.debug.flash.mode_updated', ['status' => $this->translator->trans($statusKey, [], 'synapse_admin')], 'synapse_admin'));

            return $this->redirectToRoute('synapse_admin_debug');
        }

        // Filtre "inclure les appels enfants" (agents imbriqués / éphémères).
        // Par défaut on n'affiche que les appels racines pour limiter le bruit ;
        // l'utilisateur peut cocher pour voir l'intégralité de la trace.
        $includeChildren = $request->query->getBoolean('include_children');
        $logs = $includeChildren
            ? $this->debugLogRepo->findRecent(100)
            : $this->debugLogRepo->findRoots(100);
        $total = $this->debugLogRepo->count([]);

        return $this->render('@Synapse/admin/systeme/debug.html.twig', [
            'logs' => $logs,
            'total' => $total,
            'config' => $config,
            'include_children' => $includeChildren,
        ]);
    }

    #[Route('/clear', name: 'debug_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'debug_clear');

        $count = $this->debugLogRepo->clearAll();
        $this->addFlash('success', $this->translator->trans('synapse.admin.debug.flash.cleared', ['count' => $count], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_debug');
    }

    /**
     * Purge complète : supprime toutes les conversations, messages, pièces jointes,
     * mémoires vectorielles, appels LLM et logs de debug.
     *
     * Opération irréversible — les tables sont vidées via TRUNCATE CASCADE (PostgreSQL)
     * pour respecter les clés étrangères en une seule passe.
     */
    #[Route('/purge', name: 'debug_purge', methods: ['POST'])]
    public function purge(Request $request): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);
        $this->validateCsrfToken($request, $this->csrfTokenManager, 'debug_purge');

        $connection = $this->em->getConnection();

        // Ordre respectant les FK : enfants d'abord, puis parents.
        $tables = [
            'synapse_message_attachment',
            'synapse_message',
            'synapse_conversation',
            'synapse_vector_memory',
            'synapse_llm_call',
            'synapse_debug_log',
        ];

        $counts = [];
        foreach ($tables as $table) {
            try {
                $count = (int) $connection->executeQuery(sprintf('SELECT COUNT(*) FROM %s', $table))->fetchOne();
                $counts[$table] = $count;
            } catch (\Exception) {
                $counts[$table] = 0;
            }
        }

        // TRUNCATE CASCADE pour PostgreSQL — supprime en respectant toutes les FK.
        foreach ($tables as $table) {
            try {
                $connection->executeStatement(sprintf('TRUNCATE TABLE %s CASCADE', $table));
            } catch (\Exception) {
                // Fallback DELETE pour les SGBD qui ne supportent pas TRUNCATE CASCADE.
                try {
                    $connection->executeStatement(sprintf('DELETE FROM %s', $table));
                } catch (\Exception) {
                    // Table absente ou vide — ignorer silencieusement.
                }
            }
        }

        $total = array_sum($counts);
        $this->addFlash('success', $this->translator->trans('synapse.admin.debug.flash.purged', ['count' => $total], 'synapse_admin'));

        return $this->redirectToRoute('synapse_admin_debug');
    }
}
