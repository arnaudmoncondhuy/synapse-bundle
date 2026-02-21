<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Repository;

use ArnaudMoncondhuy\SynapseBundle\Entity\SynapseModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseModel>
 */
class SynapseModelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseModel::class);
    }

    /**
     * Retourne les modèles activés pour un provider donné.
     *
     * @return SynapseModel[]
     */
    public function findEnabledForProvider(string $providerName): array
    {
        return $this->findBy(
            ['providerName' => $providerName, 'isEnabled' => true],
            ['sortOrder' => 'ASC', 'label' => 'ASC']
        );
    }

    /**
     * Retourne tous les modèles groupés par provider (pour l'admin).
     *
     * @return array<string, SynapseModel[]>
     */
    public function findAllGroupedByProvider(): array
    {
        $models = $this->findBy([], ['providerName' => 'ASC', 'sortOrder' => 'ASC']);
        $grouped = [];

        foreach ($models as $model) {
            $grouped[$model->getProviderName()][] = $model;
        }

        return $grouped;
    }
}
