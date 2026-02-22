<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Storage\Logger;

use ArnaudMoncondhuy\SynapseBundle\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Entity\DebugLog;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\DebugLogRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine-based implementation of SynapseDebugLoggerInterface.
 *
 * Persists only lightweight metadata to the database, avoiding storage of massive raw payloads.
 * This keeps the database performant while still providing dashboard visibility.
 *
 * Suitable for admin dashboards where you want to see historical exchange metadata without
 * storing full JSON request/response bodies.
 */
class DoctrineAdminLogger implements SynapseDebugLoggerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private DebugLogRepository $repository,
    ) {
    }

    public function logExchange(string $debugId, array $metadata, array $rawPayload): void
    {
        $debugLog = new DebugLog();
        $debugLog->setDebugId($debugId);

        // Store the COMPLETE payload for template rendering
        // (template needs 'turns', 'system_prompt', etc.)
        $debugLog->setData($rawPayload);

        if (isset($metadata['conversation_id'])) {
            $debugLog->setConversationId($metadata['conversation_id']);
        }

        $debugLog->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($debugLog);
        $this->em->flush();
    }

    public function findByDebugId(string $debugId): ?array
    {
        $debugLog = $this->repository->findByDebugId($debugId);

        if (!$debugLog) {
            return null;
        }

        return [
            'debug_id'       => $debugLog->getDebugId(),
            'conversation_id' => $debugLog->getConversationId(),
            'created_at'     => $debugLog->getCreatedAt(),
            'data'           => $debugLog->getData(),
        ];
    }
}
