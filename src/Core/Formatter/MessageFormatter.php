<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Core\Formatter;

use ArnaudMoncondhuy\SynapseBundle\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\MessageFormatterInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Shared\Enum\MessageRole;

/**
 * Formateur de messages pour le format OpenAI canonical
 *
 * Convertit entre le format des entités Doctrine et le format OpenAI canonical.
 */
class MessageFormatter implements MessageFormatterInterface
{
    public function __construct(
        private ?EncryptionServiceInterface $encryptionService = null,
    ) {
    }
    /**
     * Convertit les entités Message vers le format OpenAI canonical
     *
     * Format OpenAI:
     * [
     *   { "role": "user", "content": "Hello" },
     *   { "role": "assistant", "content": "Hi!", "tool_calls": [...] },
     *   { "role": "tool", "tool_call_id": "...", "content": "..." }
     * ]
     */
    public function entitiesToApiFormat(array $messageEntities): array
    {
        $messages = [];

        foreach ($messageEntities as $entity) {
            // Handle serialized entities (Doctrine converts to arrays in closure context)
            if (is_array($entity)) {
                // If it looks like already-formatted message data, try to reconstruct
                if (isset($entity['role']) && (isset($entity['content']) || isset($entity['parts']))) {
                    // Decrypt content if needed
                    $decrypted = $entity;
                    if (!empty($entity['content']) && $this->encryptionService !== null && is_string($entity['content'])) {
                        if ($this->encryptionService->isEncrypted($entity['content'])) {
                            $decrypted['content'] = $this->encryptionService->decrypt($entity['content']);
                        }
                    }
                    $messages[] = $decrypted;
                    continue;
                }
            }

            if (!$entity instanceof Message) {
                continue;
            }

            $role = $entity->getRole();
            $content = $entity->getDecryptedContent();

            // Map internal roles to OpenAI roles
            $mappedRole = $this->mapRoleToOpenAi($role);

            $messages[] = [
                'role'    => $mappedRole,
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * Map internal MessageRole enum to OpenAI role strings
     */
    private function mapRoleToOpenAi(MessageRole $role): string
    {
        return match ($role) {
            MessageRole::USER => 'user',
            MessageRole::MODEL => 'assistant',
            MessageRole::FUNCTION => 'tool',
            MessageRole::SYSTEM => 'system',
        };
    }

    /**
     * Convertit le format OpenAI canonical vers des entités Message
     *
     * Utile pour l'import de conversations ou les tests.
     * Les entités retournées ne sont PAS persistées.
     */
    public function apiFormatToEntities(array $messages, Conversation $conversation): array
    {
        $entities = [];

        foreach ($messages as $msg) {
            if (!isset($msg['role']) || !isset($msg['content'])) {
                continue;
            }

            // Déterminer la classe Message concrète depuis la conversation
            $messageClass = get_class($conversation->getMessages()->first() ?: new Message());

            $entity = new $messageClass();
            $entity->setConversation($conversation);
            $entity->setRole($this->mapRoleFromOpenAi($msg['role']));
            $entity->setContent($msg['content'] ?? '');

            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Map OpenAI role strings to internal MessageRole enum
     */
    private function mapRoleFromOpenAi(string $role): MessageRole
    {
        return match ($role) {
            'user' => MessageRole::USER,
            'assistant' => MessageRole::MODEL,
            'tool' => MessageRole::FUNCTION,
            'system' => MessageRole::SYSTEM,
            default => MessageRole::USER,
        };
    }
}
