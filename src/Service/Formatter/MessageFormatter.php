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

        // DEBUG: Log what we receive
        $debugLog = sys_get_temp_dir() . '/synapse_debug.log';
        file_put_contents($debugLog, date('H:i:s') . " [formatter] received " . count($messageEntities) . " entities\n", FILE_APPEND);

        foreach ($messageEntities as $index => $entity) {
            $entityClass = is_object($entity) ? get_class($entity) : gettype($entity);
            $isMessage = $entity instanceof Message;
            file_put_contents($debugLog, date('H:i:s') . " [formatter] entity[$index] class=$entityClass instanceof Message=" . ($isMessage ? 'YES' : 'NO') . "\n", FILE_APPEND);

            if (!$entity instanceof Message) {
                continue;
            }

            $role = $entity->getRole();
            $content = $entity->getDecryptedContent();
            file_put_contents($debugLog, date('H:i:s') . " [formatter] entity[$index] role=" . $role->value . " content_length=" . strlen($content) . "\n", FILE_APPEND);

            $messages[] = [
                'role' => strtolower($role->value),
                'parts' => [
                    ['text' => $content]
                ]
            ];
        }

        file_put_contents($debugLog, date('H:i:s') . " [formatter] returning " . count($messages) . " messages\n", FILE_APPEND);

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
