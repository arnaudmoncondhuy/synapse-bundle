<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\SynapseConversation;

/**
 * Interface pour la conversion de format des messages
 *
 * Permet de convertir entre le format des entités (ORM) et le format API (Gemini, Claude, OpenAI, etc.)
 * Cette abstraction permet de:
 * - Séparer la logique de formatage de la logique métier
 * - Supporter plusieurs formats d'API
 * - Faciliter les tests
 */
interface MessageFormatterInterface
{
    /**
     * Convertit un tableau d'entités SynapseMessage vers le format API
     *
     * @param array $messageEntities Tableau d'entités SynapseMessage
     * @return array Format compatible avec l'API (ex: Gemini, Claude)
     */
    public function entitiesToApiFormat(array $messageEntities): array;

    /**
     * Convertit un tableau au format API vers des entités SynapseMessage
     *
     * @param array $messages Messages au format API
     * @param SynapseConversation $conversation SynapseConversation parente
     * @return array Tableau d'entités SynapseMessage (non persistées)
     */
    public function apiFormatToEntities(array $messages, SynapseConversation $conversation): array;
}
