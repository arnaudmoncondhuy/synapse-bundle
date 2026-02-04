<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Formatter;

use ArnaudMoncondhuy\SynapseBundle\Contract\MessageFormatterInterface;
use ArnaudMoncondhuy\SynapseBundle\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Enum\MessageRole;

/**
 * Formateur de messages pour l'API Gemini
 *
 * Convertit entre le format des entités Doctrine et le format attendu par l'API Gemini.
 */
class MessageFormatter implements MessageFormatterInterface
{
    /**
     * Convertit les entités Message vers le format Gemini
     *
     * Format Gemini:
     * [
     *   {
     *     "role": "user",
     *     "parts": [{"text": "Hello"}]
     *   }
     * ]
     */
    public function entitiesToApiFormat(array $messageEntities): array
    {
        $messages = [];

        foreach ($messageEntities as $entity) {
            if (!$entity instanceof Message) {
                continue;
            }

            $messages[] = [
                'role' => strtolower($entity->getRole()->value),
                'parts' => [
                    ['text' => $entity->getDecryptedContent()]
                ]
            ];
        }

        return $messages;
    }

    /**
     * Convertit le format Gemini vers des entités Message
     *
     * Utile pour l'import de conversations ou les tests.
     * Les entités retournées ne sont PAS persistées.
     */
    public function apiFormatToEntities(array $messages, Conversation $conversation): array
    {
        $entities = [];

        foreach ($messages as $msg) {
            if (!isset($msg['role']) || !isset($msg['parts'])) {
                continue;
            }

            // Déterminer la classe Message concrète depuis la conversation
            $messageClass = get_class($conversation->getMessages()->first() ?: new Message());

            $entity = new $messageClass();
            $entity->setConversation($conversation);
            $entity->setRole(MessageRole::from($msg['role']));
            $entity->setContent($msg['parts'][0]['text'] ?? '');

            $entities[] = $entity;
        }

        return $entities;
    }
}
