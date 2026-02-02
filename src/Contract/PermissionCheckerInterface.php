<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

use ArnaudMoncondhuy\SynapseBundle\Entity\Conversation;

/**
 * Interface pour la vérification des permissions
 *
 * Permet au bundle d'être agnostique vis-à-vis du système de permissions
 * du projet (Symfony Security, Voter, ACL, custom).
 *
 * @example
 * ```php
 * use Symfony\Component\Security\Core\Security;
 * use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
 *
 * class AssistantPermissionChecker implements PermissionCheckerInterface
 * {
 *     public function __construct(
 *         private Security $security,
 *         private AuthorizationCheckerInterface $authChecker
 *     ) {}
 *
 *     public function canView(Conversation $conversation): bool
 *     {
 *         $user = $this->security->getUser();
 *         return $conversation->getOwner()->getId() === $user->getId()
 *             || $this->authChecker->isGranted('ROLE_ADMIN');
 *     }
 *
 *     public function canEdit(Conversation $conversation): bool
 *     {
 *         return $conversation->getOwner()->getId() === $this->security->getUser()->getId();
 *     }
 *
 *     public function canDelete(Conversation $conversation): bool
 *     {
 *         return $this->canEdit($conversation);
 *     }
 *
 *     public function canAccessAdmin(): bool
 *     {
 *         return $this->authChecker->isGranted('ROLE_ADMIN');
 *     }
 * }
 * ```
 */
interface PermissionCheckerInterface
{
    /**
     * Vérifie si l'utilisateur actuel peut voir une conversation
     *
     * @param Conversation $conversation La conversation à vérifier
     * @return bool True si l'utilisateur peut voir la conversation
     */
    public function canView(Conversation $conversation): bool;

    /**
     * Vérifie si l'utilisateur actuel peut modifier une conversation
     *
     * @param Conversation $conversation La conversation à vérifier
     * @return bool True si l'utilisateur peut modifier la conversation
     */
    public function canEdit(Conversation $conversation): bool;

    /**
     * Vérifie si l'utilisateur actuel peut supprimer une conversation
     *
     * @param Conversation $conversation La conversation à vérifier
     * @return bool True si l'utilisateur peut supprimer la conversation
     */
    public function canDelete(Conversation $conversation): bool;

    /**
     * Vérifie si l'utilisateur actuel peut accéder à l'interface d'administration
     *
     * @return bool True si l'utilisateur peut accéder à l'admin
     */
    public function canAccessAdmin(): bool;
}
