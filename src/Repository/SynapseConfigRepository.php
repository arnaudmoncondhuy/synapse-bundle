<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Repository;

use ArnaudMoncondhuy\SynapseBundle\Entity\SynapseConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour l'entité SynapseConfig
 *
 * @extends ServiceEntityRepository<SynapseConfig>
 */
class SynapseConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseConfig::class);
    }

    /**
     * Récupère la configuration pour un scope
     *
     * Si la configuration n'existe pas, en crée une avec les valeurs par défaut.
     *
     * @param string $scope Scope de la configuration
     * @return SynapseConfig Configuration
     */
    public function getConfig(string $scope = 'default'): SynapseConfig
    {
        $config = $this->findOneBy(['scope' => $scope]);

        if ($config === null) {
            $config = $this->createDefaultConfig($scope);
            $em = $this->getEntityManager();
            $em->persist($config);
            $em->flush();
        }

        return $config;
    }

    /**
     * Crée une configuration avec les valeurs par défaut
     *
     * @param string $scope Scope de la configuration
     * @return SynapseConfig Configuration par défaut
     */
    private function createDefaultConfig(string $scope): SynapseConfig
    {
        $config = new SynapseConfig();
        $config->setScope($scope);
        $config->setModel('gemini-2.0-flash-exp');
        $config->setSafetyEnabled(false);
        $config->setSafetyDefaultThreshold('BLOCK_MEDIUM_AND_ABOVE');
        $config->setGenerationTemperature(1.0);
        $config->setGenerationTopP(0.95);
        $config->setGenerationTopK(40);
        $config->setThinkingEnabled(true);
        $config->setThinkingBudget(1024);
        $config->setContextCachingEnabled(false);

        return $config;
    }

    /**
     * Liste toutes les configurations disponibles
     *
     * @return SynapseConfig[] Configurations
     */
    public function findAllConfigs(): array
    {
        return $this->findBy([], ['scope' => 'ASC']);
    }

    /**
     * Supprime une configuration
     *
     * @param string $scope Scope de la configuration
     * @return bool True si supprimée, false si non trouvée
     */
    public function deleteConfig(string $scope): bool
    {
        $config = $this->findOneBy(['scope' => $scope]);

        if ($config === null) {
            return false;
        }

        $em = $this->getEntityManager();
        $em->remove($config);
        $em->flush();

        return true;
    }
}
