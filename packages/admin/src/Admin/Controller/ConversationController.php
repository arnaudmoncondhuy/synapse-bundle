<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Admin\Controller;

use ArnaudMoncondhuy\SynapseCore\Core\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Security\AdminSecurityTrait;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Break-Glass - Accès conversations avec audit
 */
#[Route('/synapse/admin/conversations')]
class ConversationController extends AbstractController
{
    use AdminSecurityTrait;

    public function __construct(
        private ConversationManager $conversationManager,
        private PermissionCheckerInterface $permissionChecker,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Vue détaillée d'une conversation (Break-Glass)
     */
    #[Route('/{id}', name: 'synapse_admin_conversation_view')]
    public function view(string $id): Response
    {
        $this->denyAccessUnlessAdmin($this->permissionChecker);

        $conversation = $this->conversationManager->getConversation($id);

        if (!$conversation) {
            throw $this->createNotFoundException('SynapseConversation not found');
        }

        // Audit log (RGPD)
        if ($this->logger) {
            $this->logger->warning('Break-Glass access to conversation', [
                'conversation_id' => $id,
                'admin_user' => $this->getUser()?->getUserIdentifier(),
                'owner' => $conversation->getOwner()->getIdentifier(),
            ]);
        }

        $messages = $this->conversationManager->getMessages($conversation);

        return $this->render('@Synapse/admin/conversation.html.twig', [
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }
}
