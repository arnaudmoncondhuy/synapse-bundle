<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository\Brain;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Brain\MemorySource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MemorySource>
 */
class MemorySourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemorySource::class);
    }

    /**
     * Retrouve une source par (provider, externalId) — utile pour la
     * déduplication à l'ingestion répétée.
     */
    public function findByProviderAndExternalId(string $provider, string $externalId): ?MemorySource
    {
        return $this->findOneBy([
            'provider' => $provider,
            'externalId' => $externalId,
        ]);
    }

    public function findOneByUuid(Uuid $uuid): ?MemorySource
    {
        return $this->find($uuid);
    }
}
