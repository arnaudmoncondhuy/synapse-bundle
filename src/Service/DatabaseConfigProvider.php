<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Repository\SynapseConfigRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fournisseur de configuration dynamique depuis la BDD
 *
 * Lit la configuration depuis SynapseConfig et la met en cache.
 * Permet de modifier la configuration en temps réel sans redémarrage.
 */
class DatabaseConfigProvider implements ConfigProviderInterface
{
    private const CACHE_KEY = 'synapse.config.';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private SynapseConfigRepository $configRepo,
        private ?CacheInterface $cache = null,
        private string $scope = 'default'
    ) {
    }

    /**
     * Récupère la configuration pour le scope actuel
     *
     * @return array Configuration formatée pour ChatService
     */
    public function getConfig(): array
    {
        $cacheKey = self::CACHE_KEY . $this->scope;

        // Avec cache
        if ($this->cache !== null) {
            return $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->loadConfig();
            });
        }

        // Sans cache
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

    /**
     * Change le scope de configuration
     *
     * @param string $scope Nouveau scope
     */
    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }

    /**
     * Récupère le scope actuel
     *
     * @return string Scope actuel
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Charge la configuration depuis la BDD
     *
     * @return array Configuration
     */
    private function loadConfig(): array
    {
        $config = $this->configRepo->getConfig($this->scope);
        return $config->toArray();
    }
}
