<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface pour le propriétaire d'une conversation
 *
 * Permet au bundle d'être agnostique vis-à-vis de l'entité User du projet.
 * Chaque projet doit faire implémenter cette interface à son entité User.
 *
 * @example
 * ```php
 * use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationOwnerInterface;
 *
 * class User implements ConversationOwnerInterface
 * {
 *     public function getId(): ?int
 *     {
 *         return $this->id;
 *     }
 *
 *     public function getIdentifier(): string
 *     {
 *         return $this->email;
 *     }
 * }
 * ```
 */
interface ConversationOwnerInterface
{
    /**
     * Retourne l'identifiant unique du propriétaire
     *
     * @return int|string|null L'ID du propriétaire (peut être null avant persist)
     */
    public function getId(): int|string|null;

    /**
     * Retourne un identifiant humainement lisible du propriétaire
     *
     * Utilisé pour les logs, l'audit, l'affichage admin.
     * Exemples : email, username, nom complet
     *
     * @return string Identifiant lisible (email, username, etc.)
     */
    public function getIdentifier(): string;
}
