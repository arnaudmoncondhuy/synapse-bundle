<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service;

use ArnaudMoncondhuy\SynapseBundle\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\LlmClientInterface;

/**
 * Registre des clients LLM disponibles.
 *
 * Sélectionne le bon client en fonction du provider configuré en DB (ou YAML par défaut).
 * Les clients sont enregistrés automatiquement via le tag Symfony `synapse.llm_client`.
 */
class LlmClientRegistry
{
    /** @var LlmClientInterface[] Indexed by provider name */
    private array $clientMap = [];

    /**
     * @param iterable<LlmClientInterface> $clients       Clients tagués `synapse.llm_client`
     * @param ConfigProviderInterface      $configProvider Fournisseur de config DB
     * @param string                       $defaultProvider Provider YAML par défaut (bootstrap)
     */
    public function __construct(
        iterable $clients,
        private ConfigProviderInterface $configProvider,
        private string $defaultProvider = 'gemini',
    ) {
        foreach ($clients as $client) {
            $this->clientMap[$client->getProviderName()] = $client;
        }
    }

    /**
     * Retourne le client LLM actif selon la configuration DB, avec fallback YAML.
     *
     * @throws \RuntimeException Si le provider configuré n'est pas disponible.
     */
    public function getClient(): LlmClientInterface
    {
        $config = $this->configProvider->getConfig();
        $providerName = $config['provider'] ?? $this->defaultProvider;

        if (isset($this->clientMap[$providerName])) {
            return $this->clientMap[$providerName];
        }

        // Fallback sur le provider par défaut YAML
        if ($providerName !== $this->defaultProvider && isset($this->clientMap[$this->defaultProvider])) {
            return $this->clientMap[$this->defaultProvider];
        }

        throw new \RuntimeException(
            sprintf(
                'Provider LLM "%s" non disponible. Providers enregistrés : %s',
                $providerName,
                implode(', ', array_keys($this->clientMap)) ?: 'aucun'
            )
        );
    }

    /**
     * Liste tous les providers disponibles.
     *
     * @return string[]
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->clientMap);
    }
}
