<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Controller\Api;

use ArnaudMoncondhuy\SynapseBundle\Core\Chat\ChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur utilitaire pour la gestion de session.
 */
#[Route('/synapse/api')]
class ResetController extends AbstractController
{
    public function __construct(
        private ChatService $chatService,
    ) {
    }

    /**
     * Réinitialise explicitement la conversation courante.
     *
     * Vide l'historique stocké en session.
     *
     * @return JsonResponse confirmation du reset
     */
    #[Route('/reset', name: 'synapse_api_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        try {
            $this->chatService->resetConversation();

            return $this->json(['success' => true, 'message' => 'SynapseConversation reset.']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
