<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\DependencyInjection;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class SynapseExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Inject parameters
        $container->setParameter('synapse.gemini_api_key', $config['gemini_api_key']);
        $container->setParameter('synapse.model', $config['model']);

        // Load services.yaml
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Autoconfiguration: Tag classes implementing our interfaces
        $container->registerForAutoconfiguration(AiToolInterface::class)
            ->addTag('synapse.tool');

        $container->registerForAutoconfiguration(ContextProviderInterface::class)
            ->addTag('synapse.context_provider');

        $container->registerForAutoconfiguration(ConversationHandlerInterface::class)
            ->addTag('synapse.conversation_handler');
    }

    public function getAlias(): string
    {
        return 'synapse';
    }
}
