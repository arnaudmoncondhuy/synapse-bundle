<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Enum;

/**
 * Statut d'une conversation
 */
enum ConversationStatus: string
{
    /**
     * Conversation active (en cours d'utilisation)
     */
    case ACTIVE = 'ACTIVE';

    /**
     * Conversation archivée (historique, lecture seule)
     */
    case ARCHIVED = 'ARCHIVED';

    /**
     * Conversation supprimée (soft delete, purge RGPD)
     */
    case DELETED = 'DELETED';

    /**
     * Retourne tous les statuts visibles pour l'utilisateur
     */
    public static function visibleStatuses(): array
    {
        return [self::ACTIVE, self::ARCHIVED];
    }

    /**
     * Vérifie si le statut est visible
     */
    public function isVisible(): bool
    {
        return $this !== self::DELETED;
    }

    /**
     * Vérifie si la conversation peut être modifiée
     */
    public function isEditable(): bool
    {
        return $this === self::ACTIVE;
    }
}
