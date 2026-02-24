<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseConversation;

/**
 * Interface pour la politique de rétention des conversations
 *
 * Permet de personnaliser:
 * - La durée de rétention
 * - Les critères de purge
 * - Les hooks avant/après purge (logging, notifications, etc.)
 */
interface RetentionPolicyInterface
{
    /**
     * Retourne la durée de rétention en jours
     */
    public function getRetentionDays(): int;

    /**
     * Détermine si une conversation doit être purgée
     *
     * @param SynapseConversation $conversation SynapseConversation à évaluer
     * @return bool true si la conversation doit être purgée
     */
    public function shouldPurge(SynapseConversation $conversation): bool;

    /**
     * Hook appelé avant la purge d'une conversation
     *
     * Peut être utilisé pour:
     * - Logger la purge (RGPD)
     * - Archiver ailleurs
     * - Notifier l'utilisateur
     *
     * @param SynapseConversation $conversation SynapseConversation sur le point d'être purgée
     */
    public function beforePurge(SynapseConversation $conversation): void;

    /**
     * Hook appelé après la purge de toutes les conversations
     *
     * Peut être utilisé pour:
     * - Envoyer un rapport
     * - Notifier les admins
     * - Mettre à jour des métriques
     *
     * @param int $purgedCount Nombre de conversations purgées
     */
    public function afterPurge(int $purgedCount): void;
}
