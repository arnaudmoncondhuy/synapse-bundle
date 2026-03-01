<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin;

use ArnaudMoncondhuy\SynapseAdmin\Infrastructure\DependencyInjection\SynapseAdminExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Classe principale du Bundle SynapseAdmin.
 *
 * Point d'entrée pour l'intégration dans le kernel Symfony.
 * Charge l'interface d'administration : contrôleurs, templates, assets.
 *
 * Dépend de : SynapseCoreBundle
 */
class SynapseAdminBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SynapseAdminExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
