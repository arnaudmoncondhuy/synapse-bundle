<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Service\Impl;

use ArnaudMoncondhuy\SynapseBundle\Contract\ApiKeyProviderInterface;

/**
 * Implémentation par défaut de ApiKeyProviderInterface.
 *
 * Utilise la clé API configurée globalement dans synapse.yaml (via les paramètres de DI).
 */
class DefaultApiKeyProvider implements ApiKeyProviderInterface
{
    public function __construct(
        private ?string $defaultApiKey = null
    ) {
    }

    public function provideApiKey(): ?string
    {
        return $this->defaultApiKey;
    }
}
