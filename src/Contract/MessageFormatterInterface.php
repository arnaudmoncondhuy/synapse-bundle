<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

use ArnaudMoncondhuy\SynapseBundle\Entity\Conversation;

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
     * Convertit un tableau d'entités Message vers le format API
     *
     * @param array $messageEntities Tableau d'entités Message
     * @return array Format compatible avec l'API (ex: Gemini, Claude)
     */
    public function entitiesToApiFormat(array $messageEntities): array;

    /**
     * Convertit un tableau au format API vers des entités Message
     *
     * @param array $messages Messages au format API
     * @param Conversation $conversation Conversation parente
     * @return array Tableau d'entités Message (non persistées)
     */
    public function apiFormatToEntities(array $messages, Conversation $conversation): array;
}
