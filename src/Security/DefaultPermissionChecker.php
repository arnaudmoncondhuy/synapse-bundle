<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Security;

use ArnaudMoncondhuy\SynapseBundle\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Implémentation par défaut du vérificateur de permissions
 *
 * Pattern standard:
 * - Propriétaire peut voir/éditer/supprimer ses conversations
 * - Admin peut tout voir (configurable)
 * - Mode développement: accès total si pas de security
 */
class DefaultPermissionChecker implements PermissionCheckerInterface
{
    public function __construct(
        private ?Security $security = null,
        private ?AuthorizationCheckerInterface $authChecker = null,
        private string $adminRole = 'ROLE_ADMIN'
    ) {
    }

    public function canView(Conversation $conversation): bool
    {
        // Pattern 1: Pas d'auth = accès total (mode dev)
        if ($this->security === null) {
            return true;
        }

        $user = $this->security->getUser();
        if (!$user instanceof ConversationOwnerInterface) {
            return false;
        }

        // Pattern 2: Admin peut tout voir
        if ($this->authChecker?->isGranted($this->adminRole)) {
            return true;
        }

        // Pattern 3: Propriétaire peut voir
        $owner = $conversation->getOwner();
        if ($owner === null) {
            return false;
        }

        return $owner->getIdentifier() === $user->getIdentifier();
    }

    public function canEdit(Conversation $conversation): bool
    {
        // Plus strict: seul le propriétaire peut éditer
        if ($this->security === null) {
            return true; // Mode dev
        }

        $user = $this->security->getUser();
        if (!$user instanceof ConversationOwnerInterface) {
            return false;
        }

        $owner = $conversation->getOwner();
        if ($owner === null) {
            return false;
        }

        return $owner->getIdentifier() === $user->getIdentifier();
    }

    public function canDelete(Conversation $conversation): bool
    {
        // Même logique que canEdit
        return $this->canEdit($conversation);
    }

    public function canAccessAdmin(): bool
    {
        if ($this->authChecker === null) {
            return true; // Mode dev
        }

        return $this->authChecker->isGranted($this->adminRole);
    }
}
