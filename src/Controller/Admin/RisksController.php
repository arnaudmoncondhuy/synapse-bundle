<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Admin;

use ArnaudMoncondhuy\SynapseBundle\Repository\ConversationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des risques - Vue "Ange Gardien"
 */
#[Route('/synapse/admin/risks')]
class RisksController extends AbstractController
{
    public function __construct(
        private ConversationRepository $conversationRepo
    ) {
    }

    /**
     * Liste des conversations à risque
     */
    #[Route('', name: 'synapse_admin_risks')]
    public function index(Request $request): Response
    {
        $limit = (int) $request->query->get('limit', 100);
        $conversations = $this->conversationRepo->findPendingRisks($limit);

        return $this->render('@Synapse/admin/risks.html.twig', [
            'conversations' => $conversations,
            'total' => count($conversations),
        ]);
    }
}
