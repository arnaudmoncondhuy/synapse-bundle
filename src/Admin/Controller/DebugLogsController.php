<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Controller;

use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\SynapseDebugLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Interface d'administration des journaux de debug
 *
 * Permet de consulter l'historique des appels LLM debuggés et tracés en base de données.
 */
#[Route('/synapse/admin/debug-logs')]
class DebugLogsController extends AbstractController
{
    public function __construct(
        private SynapseDebugLogRepository $debugLogRepo,
    ) {}

    /**
     * Liste les logs de debug récents
     */
    #[Route('', name: 'synapse_admin_debug_logs', methods: ['GET'])]
    public function index(): Response
    {
        $debugLogs = $this->debugLogRepo->findRecent(100);

        return $this->render('@Synapse/admin/debug_logs.html.twig', [
            'logs' => $debugLogs,
        ]);
    }

    /**
     * Vide tous les logs de debug
     */
    #[Route('/clear', name: 'synapse_admin_debug_logs_clear', methods: ['POST'])]
    public function clear(): Response
    {
        $count = $this->debugLogRepo->clearAll();

        $this->addFlash('success', sprintf('%d logs de debug ont été supprimés.', $count));

        return $this->redirectToRoute('synapse_admin_debug_logs');
    }
}
