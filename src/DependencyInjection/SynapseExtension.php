<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\DependencyInjection;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseBundle\Service\Impl\LibsodiumEncryptionService;
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
                __DIR__.'/../Resources/views' => 'Synapse',
            ],
        ]);

        // 2. Enregistrement des assets pour AssetMapper (Stimulus controllers)
        // Cela permet de faire un simple `import 'synapse/chat_controller'` dans l'app
        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    realpath(dirname(__DIR__, 2).'/assets') => 'synapse',
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
        $container->setParameter('synapse.model', $config['model']);

        // Définition du chemin par défaut si non spécifié
        $personasPath = $config['personas_path'] ?? (dirname(__DIR__, 1).'/Resources/config/personas.json');
        $container->setParameter('synapse.personas_path', $personasPath);

        // Thinking configuration
        $container->setParameter('synapse.thinking.enabled', $config['thinking']['enabled'] ?? true);
        $container->setParameter('synapse.thinking.budget', $config['thinking']['budget'] ?? 1024);

        // Vertex AI configuration (always enabled)
        $container->setParameter('synapse.vertex.project_id', $config['vertex']['project_id']);
        $container->setParameter('synapse.vertex.region', $config['vertex']['region'] ?? 'europe-west1');
        $container->setParameter('synapse.vertex.service_account_json', $config['vertex']['service_account_json']);

        // Safety Settings configuration
        $container->setParameter('synapse.safety_settings.enabled', $config['safety_settings']['enabled'] ?? false);
        $container->setParameter('synapse.safety_settings.default_threshold', $config['safety_settings']['default_threshold'] ?? 'BLOCK_MEDIUM_AND_ABOVE');
        $container->setParameter('synapse.safety_settings.thresholds', $config['safety_settings']['thresholds'] ?? [
            'hate_speech' => 'BLOCK_MEDIUM_AND_ABOVE',
            'dangerous_content' => 'BLOCK_MEDIUM_AND_ABOVE',
            'harassment' => 'BLOCK_MEDIUM_AND_ABOVE',
            'sexually_explicit' => 'BLOCK_MEDIUM_AND_ABOVE',
        ]);

        // Generation Config
        $container->setParameter('synapse.generation_config.temperature', $config['generation_config']['temperature'] ?? 1.0);
        $container->setParameter('synapse.generation_config.top_p', $config['generation_config']['top_p'] ?? 0.95);
        $container->setParameter('synapse.generation_config.top_k', $config['generation_config']['top_k'] ?? 40);
        $container->setParameter('synapse.generation_config.max_output_tokens', $config['generation_config']['max_output_tokens'] ?? null);
        $container->setParameter('synapse.generation_config.stop_sequences', $config['generation_config']['stop_sequences'] ?? []);

        // Context Caching configuration
        $container->setParameter('synapse.context_caching.enabled', $config['context_caching']['enabled'] ?? false);
        $container->setParameter('synapse.context_caching.cached_content_id', $config['context_caching']['cached_content_id'] ?? null);

        // ========== NOUVELLES CONFIGURATIONS (Refonte) ==========

        // Persistence configuration
        $container->setParameter('synapse.persistence.enabled', $config['persistence']['enabled'] ?? false);
        $container->setParameter('synapse.persistence.handler', $config['persistence']['handler'] ?? 'session');

        // Encryption configuration
        $container->setParameter('synapse.encryption.enabled', $config['encryption']['enabled'] ?? false);
        $container->setParameter('synapse.encryption.key', $config['encryption']['key'] ?? null);

        // Token tracking configuration
        $container->setParameter('synapse.token_tracking.enabled', $config['token_tracking']['enabled'] ?? false);
        $container->setParameter('synapse.token_tracking.pricing', $config['token_tracking']['pricing'] ?? []);

        // Risk detection configuration
        $container->setParameter('synapse.risk_detection.enabled', $config['risk_detection']['enabled'] ?? false);
        $container->setParameter('synapse.risk_detection.auto_register_tool', $config['risk_detection']['auto_register_tool'] ?? true);

        // Retention configuration
        $container->setParameter('synapse.retention.days', $config['retention']['days'] ?? 30);

        // Admin configuration
        $container->setParameter('synapse.admin.enabled', $config['admin']['enabled'] ?? false);
        $container->setParameter('synapse.admin.route_prefix', $config['admin']['route_prefix'] ?? '/synapse/admin');

        // UI configuration
        $container->setParameter('synapse.ui.sidebar_enabled', $config['ui']['sidebar_enabled'] ?? true);

        // Register encryption service if enabled
        if ($config['encryption']['enabled']) {
            $container
                ->register('synapse.encryption_service', LibsodiumEncryptionService::class)
                ->setArguments([$config['encryption']['key']])
                ->setAutowired(true)
                ->setPublic(false);

            $container->setAlias(
                EncryptionServiceInterface::class,
                'synapse.encryption_service'
            );
        }

        // Chargement des services
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');

        // Auto-configuration : Ajout automatique de Tags pour les classes implémentant nos interfaces
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
