<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use ArnaudMoncondhuy\SynapseBundle\DependencyInjection\SynapseExtension;

class SynapseBundle extends Bundle implements PrependExtensionInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SynapseExtension();
    }

    /**
     * Prepend configuration to expose assets via AssetMapper.
     */
    public function prepend(ContainerBuilder $container): void
    {
        // Add the bundle's assets directory to AssetMapper paths
        $bundleAssetsPath = __DIR__ . '/../assets';

        if (is_dir($bundleAssetsPath)) {
            $container->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        $bundleAssetsPath => 'synapse',
                    ],
                ],
            ]);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
