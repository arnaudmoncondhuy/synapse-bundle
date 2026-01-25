<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\DependencyInjection;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ApiKeyProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Extension principale du conteneur de dépendance pour SynapseBundle.
 *
 * Responsabilités :
 * 1. Charger la configuration et injecter les paramètres.
 * 2. Charger les services définis dans `config/services.yaml`.
 * 3. Configurer l'auto-configuration pour simplifier l'utilisation des interfaces (Tags automatiques).
 * 4. Pré-configurer Twig (Namespace) et AssetMapper (chemins) via `prepend()`.
 */
class SynapseExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Pré-configuration des autres bundles (Twig, AssetMapper).
     *
     * Cette méthode est appelée avant le chargement des configurations de l'application.
     * Elle permet au bundle de s'injecter automatiquement sans configuration manuelle de l'utilisateur.
     */
    public function prepend(ContainerBuilder $container): void
    {
        // 1. Enregistrement du namespace Twig @Synapse
        $container->prependExtensionConfig('twig', [
            'paths' => [
                __DIR__ . '/../Resources/views' => 'Synapse',
            ],
        ]);

        // 2. Enregistrement des assets pour AssetMapper (Stimulus controllers)
        // Cela permet de faire un simple `import 'synapse/chat_controller'` dans l'app
        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    realpath(dirname(__DIR__, 2) . '/assets') => 'synapse',
                ],
            ],
        ]);
    }

    /**
     * Chargement principal de la configuration du bundle.
     *
     * @param array $configs configurations fusionnées
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Injection des paramètres dans le conteneur
        $container->setParameter('synapse.api_key', $config['api_key']);
        $container->setParameter('synapse.model', $config['model']);

        // Définition du chemin par défaut si non spécifié
        $personasPath = $config['personas_path'] ?? (dirname(__DIR__, 1) . '/Resources/config/personas.json');
        $container->setParameter('synapse.personas_path', $personasPath);

        // Chargement des services
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        // Auto-configuration : Ajout automatique de Tags pour les classes implémentant nos interfaces
        $container->registerForAutoconfiguration(AiToolInterface::class)
            ->addTag('synapse.tool');

        $container->registerForAutoconfiguration(ContextProviderInterface::class)
            ->addTag('synapse.context_provider');

        $container->registerForAutoconfiguration(ConversationHandlerInterface::class)
            ->addTag('synapse.conversation_handler');

        $container->registerForAutoconfiguration(ApiKeyProviderInterface::class)
            ->addTag('synapse.api_key_provider');
    }

    public function getAlias(): string
    {
        return 'synapse';
    }
}
