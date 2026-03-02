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

        // NOTE: AssetMapper paths are registered ONLY via Composer paths or symlinks
        // in the local assets/ directory. Each application is responsible for creating symlinks
        // or using Composer vendor paths (automatic via composer path repositories).
        // This avoids absolute paths outside /app that may not exist in containers.
        // For Packagist users: symlinks in assets/ are created by synapse:doctor --fix
        // For path repositories (dev): assets are accessible via /app/vendor/arnaudmoncondhuy/synapse-admin/assets
    }

    /**
     * Chargement principal de la configuration du bundle.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configDir = \dirname(__DIR__, 3) . '/config';
        $loader = new YamlFileLoader($container, new FileLocator($configDir));
        $loader->load('admin.yaml');

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
