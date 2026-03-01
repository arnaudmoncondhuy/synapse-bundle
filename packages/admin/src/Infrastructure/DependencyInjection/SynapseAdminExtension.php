<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseAdmin\Infrastructure\DependencyInjection;

use ArnaudMoncondhuy\SynapseAdmin\Infrastructure\Twig\SynapseRuntime;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Extension du bundle SynapseAdmin.
 *
 * Responsabilités :
 * 1. Enregistrer les chemins Twig pour les templates admin (V1 + V2)
 * 2. Configurer AssetMapper pour les assets admin
 * 3. Charger les services admin
 * 4. Configurer les globals Twig (SynapseRuntime)
 */
class SynapseAdminExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Pré-configuration des autres bundles (Twig, AssetMapper).
     */
    public function prepend(ContainerBuilder $container): void
    {
        // Enregistrement du namespace Twig @Synapse
        $viewsPath = \dirname(__DIR__) . '/Resources/views';

        $container->prependExtensionConfig('twig', [
            'paths' => [
                $viewsPath => 'Synapse',
            ],
        ]);

        // Enregistrement du chemin réel des assets admin dans AssetMapper.
        // Cela permet à AssetMapper de valider les fichiers CSS référencés via les symlinks
        // assets/synapse-admin → vendor/arnaudmoncondhuy/synapse-admin/assets (créés par synapse:doctor --fix).
        if ($container->hasExtension('framework')) {
            $assetsDir = realpath(\dirname(__DIR__, 3) . '/assets') ?: \dirname(__DIR__, 3) . '/assets';
            $container->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        $assetsDir => 'synapse-admin',
                    ],
                ],
            ]);
        }
    }

    /**
     * Chargement principal de la configuration du bundle.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configDir = \dirname(__DIR__, 3) . '/config';
        $loader = new YamlFileLoader($container, new FileLocator($configDir));
        $loader->load('admin.yaml');
        $loader->load('admin_v2.yaml');

        // Configure SynapseRuntime with version parameter from core
        if ($container->hasParameter('synapse.version')) {
            $container->getDefinition(SynapseRuntime::class)
                ->setArgument('$version', '%synapse.version%');
        }
    }

    public function getAlias(): string
    {
        return 'synapse_admin';
    }
}
