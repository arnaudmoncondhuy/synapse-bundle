<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Impl;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Implémentation par défaut du stockage de conversation : Session PHP.
 *
 * Stocke l'historique des échanges directement dans la session utilisateur Symfony.
 * Idéal pour des discussions éphémères qui ne nécessitent pas de persistance long terme.
 */
class SessionConversationHandler implements ConversationHandlerInterface
{
    private const SESSION_KEY = 'synapse_chat_history';
    private const MAX_HISTORY_ITEMS = 50;

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function loadHistory(): array
    {
        $session = $this->requestStack->getSession();

        return $session->get(self::SESSION_KEY, []);
    }

    /**
     * {@inheritDoc}
     * Implémente une limite glissante (Rolling Window) pour ne pas saturer la session.
     */
    public function saveHistory(array $history): void
    {
        $session = $this->requestStack->getSession();

        // Limit history size to prevent session bloat
        if (count($history) > self::MAX_HISTORY_ITEMS) {
            $history = array_slice($history, -self::MAX_HISTORY_ITEMS);
        }

        $session->set(self::SESSION_KEY, $history);
    }

    public function clearHistory(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
    }
}
