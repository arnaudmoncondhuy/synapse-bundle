<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Repository;

use ArnaudMoncondhuy\SynapseBundle\Entity\SynapseConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les presets de configuration Synapse
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
     * Retourne le preset actif pour un scope, ou en crée un par défaut si aucun n'existe.
     */
    public function findActiveForScope(string $scope = 'default'): SynapseConfig
    {
        $config = $this->findOneBy(['scope' => $scope, 'isActive' => true]);

        if ($config !== null) {
            return $config;
        }

        // Fallback : premier preset du scope
        $config = $this->findOneBy(['scope' => $scope], ['id' => 'ASC']);

        if ($config !== null) {
            // Auto-activate it
            $config->setIsActive(true);
            $this->getEntityManager()->flush();
            return $config;
        }

        // Aucun preset — créer le défaut
        $config = $this->createDefaultConfig($scope);
        $em = $this->getEntityManager();
        $em->persist($config);
        $em->flush();

        return $config;
    }

    /**
     * Active un preset et désactive tous les autres du même scope.
     */
    public function activatePreset(SynapseConfig $preset): void
    {
        $em = $this->getEntityManager();

        // Désactiver tous les presets du scope
        $em->createQuery(
            'UPDATE ' . SynapseConfig::class . ' c SET c.isActive = false WHERE c.scope = :scope'
        )->setParameter('scope', $preset->getScope())->execute();

        // Activer le preset cible
        $preset->setIsActive(true);
        $em->flush();
    }

    /**
     * Tous les presets d'un scope, triés par id.
     *
     * @return SynapseConfig[]
     */
    public function findForScope(string $scope): array
    {
        return $this->findBy(['scope' => $scope], ['id' => 'ASC']);
    }

    /**
     * Tous les presets, triés par scope puis id.
     *
     * @return SynapseConfig[]
     */
    public function findAllPresets(): array
    {
        return $this->findBy([], ['scope' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Crée un preset avec les valeurs par défaut pour un scope donné.
     */
    private function createDefaultConfig(string $scope): SynapseConfig
    {
        $config = new SynapseConfig();
        $config->setScope($scope);
        $config->setName('Preset par défaut');
        $config->setIsActive(true);
        $config->setProviderName('gemini');
        $config->setModel('gemini-2.5-flash');
        $config->setSafetyEnabled(false);
        $config->setSafetyDefaultThreshold('BLOCK_MEDIUM_AND_ABOVE');
        $config->setGenerationTemperature(1.0);
        $config->setGenerationTopP(0.95);
        $config->setGenerationTopK(40);
        $config->setThinkingEnabled(true);
        $config->setThinkingBudget(1024);
        $config->setContextCachingEnabled(false);
        $config->setRetentionDays(30);
        $config->setContextLanguage('fr');

        return $config;
    }
}
