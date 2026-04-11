<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les presets LLM.
 *
 * Un seul preset peut être actif à la fois (pas de scope).
 *
 * @extends ServiceEntityRepository<SynapseModelPreset>
 */
class SynapseModelPresetRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {
        parent::__construct($registry, SynapseModelPreset::class);
    }

    /**
     * Retourne le preset actif, ou en crée un par défaut si aucun n'existe.
     */
    public function findActive(): SynapseModelPreset
    {
        $preset = $this->findOneBy(['isActive' => true]);

        if (null !== $preset) {
            return $preset;
        }

        // Fallback : premier preset
        $preset = $this->findOneBy([], ['id' => 'ASC']);

        if (null !== $preset) {
            // Auto-activate it
            $preset->setIsActive(true);
            $this->getEntityManager()->flush();

            return $preset;
        }

        // Aucun preset — créer le défaut
        $preset = $this->createDefaultPreset();
        $em = $this->getEntityManager();
        $em->persist($preset);
        $em->flush();

        return $preset;
    }

    public function findByKey(string $key): ?SynapseModelPreset
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Active un preset et désactive tous les autres.
     */
    public function activate(SynapseModelPreset $preset): void
    {
        $em = $this->getEntityManager();

        // Désactiver tous les presets
        $em->createQuery(
            'UPDATE '.SynapseModelPreset::class.' p SET p.isActive = false'
        )->execute();

        // Activer le preset cible
        $preset->setIsActive(true);
        $em->flush();
    }

    /**
     * Tous les presets persistants, triés par id. Utilisé pour l'admin.
     * Exclut les éphémères.
     *
     * @return SynapseModelPreset[]
     */
    public function findAllPresets(): array
    {
        /** @var SynapseModelPreset[] $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.isEphemeral = false')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Retourne tous les presets éphémères, triés du plus récent au plus ancien.
     *
     * @return SynapseModelPreset[]
     */
    public function findEphemeral(): array
    {
        /** @var SynapseModelPreset[] $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.isEphemeral = true')
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Retourne tous les presets éphémères dont la rétention est expirée.
     *
     * @return SynapseModelPreset[]
     */
    public function findExpiredEphemeral(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        /** @var SynapseModelPreset[] $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.isEphemeral = true')
            ->andWhere('p.retentionUntil IS NULL OR p.retentionUntil < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @deprecated utiliser {@see findEphemeral()}
     *
     * @return SynapseModelPreset[]
     */
    public function findSandbox(): array
    {
        return $this->findEphemeral();
    }

    /**
     * Crée un preset avec les valeurs par défaut.
     */
    private function createDefaultPreset(): SynapseModelPreset
    {
        $preset = new SynapseModelPreset();
        $preset->setName('Preset par défaut');
        $preset->setIsActive(true);

        // Trouver le premier provider actif
        $enabledProviders = $this->providerRepo->findEnabled();
        $providerName = '';
        $modelName = '';

        if (!empty($enabledProviders)) {
            $provider = $enabledProviders[0];
            $providerName = $provider->getName();

            // Trouver le premier modèle pour ce provider
            $models = $this->capabilityRegistry->getModelsForProvider($providerName);
            if (!empty($models)) {
                $modelName = $models[0];
            }
        }

        $preset->setProviderName($providerName);
        $preset->setModel($modelName);

        $preset->setGenerationTemperature(1.0);
        $preset->setGenerationTopP(0.95);
        $preset->setGenerationTopK(40);

        return $preset;
    }
}
