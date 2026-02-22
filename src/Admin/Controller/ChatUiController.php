<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Admin\Controller;

use ArnaudMoncondhuy\SynapseBundle\Core\Manager\ConversationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur UI de chat fourni par le bundle.
 *
 * Expose la route `/synapse/chat` prête à l'emploi pour tout projet
 * intégrant le bundle. Le template peut être surchargé via
 * `templates/bundles/SynapseBundle/chat/page.html.twig`.
 */
#[Route('/synapse/chat', name: 'synapse_chat', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
class ChatUiController extends AbstractController
{
    public function __construct(
        private ?ConversationManager $conversationManager = null,
    ) {
    }

    public function __invoke(Request $request, ?Profiler $profiler): Response
    {
        if ($profiler) {
            $profiler->disable();
        }

        $history = [];
        $currentConversationId = $request->query->get('conversation', '');
        $user = $this->getUser();

        if (!empty($currentConversationId) && $this->conversationManager) {
            $conversation = $this->conversationManager->getConversation($currentConversationId, $user);
            if ($conversation) {
                $history = $this->conversationManager->getHistoryArray($conversation);
            }
        }

        return $this->render('@Synapse/chat/page.html.twig', [
            'history' => $history,
            'currentConversationId' => $currentConversationId,
        ]);
    }
}
