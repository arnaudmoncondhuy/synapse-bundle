<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Controller\Api;

use ArnaudMoncondhuy\SynapseBundle\Service\ChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/synapse/api')]
class ResetController extends AbstractController
{
    public function __construct(
        private ChatService $chatService,
    ) {
    }

    #[Route('/reset', name: 'synapse_api_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        try {
            $this->chatService->resetConversation();
            return $this->json(['success' => true, 'message' => 'Conversation reset.']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
