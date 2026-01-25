<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Tests;

use ArnaudMoncondhuy\SynapseBundle\SynapseBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\StimulusBundle\StimulusBundle;

class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new StimulusBundle(),
            new SynapseBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'test' => true,
            'secret' => 'video-games-are-for-children',
            'session' => [
                'storage_factory_id' => 'session.storage.factory.mock_file',
            ],
            'asset_mapper' => [
                'paths' => [
                    'assets/' => 'synapse_bundle',
                ],
            ],
            'http_method_override' => false,
        ]);

        $container->loadFromExtension('twig', [
            'default_path' => __DIR__.'/../templates',
        ]);

        // Synapse Bundle Configuration
        $container->loadFromExtension('synapse', [
            // 'api_key' => 'fake-api-key', // Removed as it is strictly dynamic now
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/../config/routes.yaml');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/synapse-bundle/cache/'.$this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/synapse-bundle/log/'.$this->getEnvironment();
    }
}
