<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Repository;

use ArnaudMoncondhuy\SynapseBundle\Entity\Conversation;
use ArnaudMoncondhuy\SynapseBundle\Entity\Message;
use ArnaudMoncondhuy\SynapseBundle\Enum\MessageRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité Message
 *
 * @template T of Message
 * @extends ServiceEntityRepository<T>
 *
 * Note : Ce repository est abstrait car Message est une MappedSuperclass.
 *        Les projets doivent créer leur propre repository qui étend celui-ci.
 */
abstract class MessageRepository extends ServiceEntityRepository
{
    /**
     * Trouve les messages d'une conversation
     *
     * @param Conversation $conversation Conversation
     * @param int $limit Nombre maximum de résultats (0 = illimité)
     * @return Message[] Messages ordonnés par date
     */
    public function findByConversation(Conversation $conversation, int $limit = 0): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve les derniers messages d'une conversation
     *
     * @param Conversation $conversation Conversation
     * @param int $limit Nombre de messages
     * @return Message[] Messages ordonnés par date (plus récents en premier)
     */
    public function findLastMessages(Conversation $conversation, int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les statistiques d'usage des tokens depuis une date
     *
     * @param \DateTimeInterface $since Date de début
     * @return array{prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int}
     */
    public function getUsageStatsSince(\DateTimeInterface $since): array
    {
        $result = $this->createQueryBuilder('m')
            ->select(
                'COALESCE(SUM(m.promptTokens), 0) as prompt_tokens',
                'COALESCE(SUM(m.completionTokens), 0) as completion_tokens',
                'COALESCE(SUM(m.thinkingTokens), 0) as thinking_tokens',
                'COALESCE(SUM(m.totalTokens), 0) as total_tokens'
            )
            ->where('m.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'prompt_tokens' => (int) $result['prompt_tokens'],
            'completion_tokens' => (int) $result['completion_tokens'],
            'thinking_tokens' => (int) $result['thinking_tokens'],
            'total_tokens' => (int) $result['total_tokens'],
        ];
    }

    /**
     * Compte les messages par rôle depuis une date
     *
     * @param \DateTimeInterface $since Date de début
     * @return array<string, int> Nombre de messages par rôle
     */
    public function countByRoleSince(\DateTimeInterface $since): array
    {
        $results = $this->createQueryBuilder('m')
            ->select('m.role', 'COUNT(m.id) as count')
            ->where('m.createdAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('m.role')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['role']->value] = (int) $result['count'];
        }

        return $counts;
    }

    /**
     * Trouve les messages avec feedback négatif
     *
     * @param int $limit Nombre maximum de résultats
     * @return Message[] Messages avec feedback négatif
     */
    public function findNegativeFeedback(int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.feedback = -1')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les messages bloqués par les filtres de sécurité
     *
     * @param \DateTimeInterface|null $since Date de début (optionnel)
     * @param int $limit Nombre maximum de résultats
     * @return Message[] Messages bloqués
     */
    public function findBlockedMessages(?\DateTimeInterface $since = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.blocked = true')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('m.createdAt >= :since')
               ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les messages avec thinking (Gemini 2.5+)
     *
     * @param \DateTimeInterface $since Date de début
     * @return int Nombre de messages avec thinking
     */
    public function countThinkingMessagesSince(\DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.createdAt >= :since')
            ->andWhere('m.thinkingTokens > 0')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprime les messages d'une conversation (hard delete)
     *
     * @param Conversation $conversation Conversation dont supprimer les messages
     * @return int Nombre de messages supprimés
     */
    public function deleteByConversation(Conversation $conversation): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->getQuery()
            ->execute();
    }
}
