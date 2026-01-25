<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle;

use ArnaudMoncondhuy\SynapseBundle\DependencyInjection\SynapseExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Classe principale du Bundle Synapse.
 *
 * Point d'entrée pour l'intégration dans le kernel Symfony.
 */
class SynapseBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SynapseExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
