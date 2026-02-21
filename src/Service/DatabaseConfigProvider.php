<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseProviderRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fournisseur de configuration dynamique depuis la BDD
 *
 * Fusionne le preset actif (SynapseConfig) avec les credentials du provider (SynapseProvider).
 * Met en cache le résultat pour éviter les requêtes répétées.
 */
class DatabaseConfigProvider implements ConfigProviderInterface
{
    private const CACHE_KEY = 'synapse.config.';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private SynapseProviderRepository $providerRepo,
        private ?CacheInterface $cache = null,
        private string $scope = 'default'
    ) {
    }

    /**
     * Récupère la configuration fusionnée (preset + credentials provider) pour le scope actuel.
     *
     * @return array Configuration formatée pour les services LLM
     */
    public function getConfig(): array
    {
        $cacheKey = self::CACHE_KEY . $this->scope;

        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->loadConfig();
            });
        }

        return $this->loadConfig();
    }

    /**
     * Invalide le cache de configuration
     */
    public function clearCache(): void
    {
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY . $this->scope);
        }
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Charge et fusionne la configuration depuis la BDD.
     */
    private function loadConfig(): array
    {
        $preset = $this->configRepo->findActiveForScope($this->scope);
        $config = $preset->toArray();

        // Merge provider credentials from SynapseProvider
        $providerName = $config['provider'];
        $provider = $this->providerRepo->findByName($providerName);

        if ($provider !== null && $provider->isEnabled()) {
            $config['provider_credentials'] = $provider->getCredentials();
        } else {
            $config['provider_credentials'] = [];
        }

        return $config;
    }
}
