<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseBundle\Core\Manager\ConversationManager;

/**
 * Gestionnaire de conversations basé sur Doctrine
 *
 * Implémente ConversationHandlerInterface pour charger/sauvegarder
 * l'historique depuis la base de données.
 *
 * Remplace SessionConversationHandler quand la persistence est activée.
 */
class DatabaseConversationHandler implements ConversationHandlerInterface
{
    public function __construct(
        private ConversationManager $conversationManager
    ) {
    }

    /**
     * Charge l'historique depuis la BDD et formate en OpenAI canonical
     *
     * @return array Historique formaté en OpenAI canonical
     */
    public function loadHistory(): array
    {
        $conversation = $this->conversationManager->getCurrentConversation();

        if ($conversation === null) {
            return [];
        }

        $messages = $this->conversationManager->getMessages($conversation);

        // Formater en OpenAI canonical format
        $history = [];
        foreach ($messages as $message) {
            // Ne pas inclure les messages système dans l'historique
            if ($message->getRole() === MessageRole::SYSTEM) {
                continue;
            }

            $history[] = [
                'role'    => $this->convertRoleToOpenAi($message->getRole()),
                'content' => $message->getDecryptedContent(),
            ];
        }

        return $history;
    }

    /**
     * Sauvegarde l'historique (géré par ConversationManager)
     *
     * La sauvegarde est gérée par ConversationManager::saveMessage()
     * appelé depuis ChatService, donc cette méthode est un placeholder.
     *
     * @param array $history Historique à sauvegarder
     */
    public function saveHistory(array $history): void
    {
        // Sauvegarde gérée par ConversationManager dans ChatService
        // Rien à faire ici
    }

    /**
     * Efface l'historique (soft delete conversation)
     */
    public function clearHistory(): void
    {
        $conversation = $this->conversationManager->getCurrentConversation();

        if ($conversation !== null) {
            $this->conversationManager->deleteConversation($conversation);
            $this->conversationManager->setCurrentConversation(null);
        }
    }

    /**
     * Convertit un MessageRole vers le format OpenAI canonical
     *
     * @param MessageRole $role Rôle de notre enum
     * @return string Rôle OpenAI ('user', 'assistant', 'tool')
     */
    private function convertRoleToOpenAi(MessageRole $role): string
    {
        return match ($role) {
            MessageRole::USER => 'user',
            MessageRole::MODEL => 'assistant',
            MessageRole::FUNCTION => 'tool',
            MessageRole::SYSTEM => 'user', // Fallback (ne devrait pas arriver)
        };
    }
}
