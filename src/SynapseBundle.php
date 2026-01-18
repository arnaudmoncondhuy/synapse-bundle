<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use ArnaudMoncondhuy\SynapseBundle\DependencyInjection\SynapseExtension;

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
