<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Contract;

/**
 * Interface pour la gestion de la persistance de l'historique de conversation.
 *
 * Cette interface permet d'abstraire le stockage des échanges.
 * Les implémentations peuvent stocker l'historique en Session (par défaut), en base de données,
 * dans Redis, ou tout autre système de persistance.
 */
interface ConversationHandlerInterface
{
    /**
     * Charge l'historique complet de la conversation courante.
     *
     * @return array<int, array{role: string, parts: array<int, array<string, mixed>>}> liste des messages au format attendu par Gemini
     */
    public function loadHistory(): array;

    /**
     * Sauvegarde le nouvel état de l'historique de conversation.
     *
     * Cette méthode est appelée après chaque échange (requête utilisateur + réponse IA)
     * pour mettre à jour la persistance.
     *
     * @param array<int, array{role: string, parts: array<int, array<string, mixed>>}> $history L'historique complet à sauvegarder
     */
    public function saveHistory(array $history): void;

    /**
     * Purge l'historique pour démarrer une nouvelle conversation vierge.
     */
    public function clearHistory(): void;
}
