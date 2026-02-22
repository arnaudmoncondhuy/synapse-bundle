<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\DependencyInjection;

use ArnaudMoncondhuy\SynapseBundle\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\ConversationHandlerInterface;
use ArnaudMoncondhuy\SynapseBundle\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\ConversationRepository;
use ArnaudMoncondhuy\SynapseBundle\Storage\Repository\MessageRepository;
use ArnaudMoncondhuy\SynapseBundle\Core\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseBundle\Security\LibsodiumEncryptionService;
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
        // Support both: dev (src/Infrastructure/) and vendor (installed bundle)
        $viewsPath = dirname(__DIR__, 2) . '/Infrastructure/Resources/views';
        if (!is_dir($viewsPath)) {
            // Fallback for vendor install
            $viewsPath = dirname(__DIR__) . '/Resources/views';
        }

        $container->prependExtensionConfig('twig', [
            'paths' => [
                $viewsPath => 'Synapse',
            ],
        ]);

        // 2. Enregistrement des assets pour AssetMapper (Stimulus controllers)
        $container->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    realpath(dirname(__DIR__, 3) . '/assets') => 'synapse',
                ],
            ],
        ]);

        // 3. Auto-configuration du mapping Doctrine pour les entités du bundle.
        if ($container->hasExtension('doctrine')) {
            $alreadyMapped = false;
            foreach ($container->getExtensionConfig('doctrine') as $doctrineConfig) {
                if (isset($doctrineConfig['orm']['mappings']['SynapseBundle'])) {
                    $alreadyMapped = true;
                    break;
                }
            }

            if (!$alreadyMapped) {
                $container->prependExtensionConfig('doctrine', [
                    'orm' => [
                        'mappings' => [
                            'SynapseBundle' => [
                                'type'      => 'attribute',
                                'is_bundle' => false,
                                'dir'       => dirname(__DIR__, 2) . '/Storage/Entity',
                                'prefix'    => 'ArnaudMoncondhuy\\SynapseBundle\\Storage\\Entity',
                                'alias'     => 'Synapse',
                            ],
                        ],
                    ],
                ]);
            }
        }
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

        // ── Personas ──────────────────────────────────────────────────────────
        $personasPath = $config['personas_path'] ?? (dirname(__DIR__) . '/Infrastructure/Resources/config/personas.json');
        // Fallback for vendor install
        if (!is_file($personasPath)) {
            $personasPath = dirname(__DIR__) . '/Resources/config/personas.json';
        }
        $container->setParameter('synapse.personas_path', $personasPath);

        // ── Persistence ───────────────────────────────────────────────────────
        $container->setParameter('synapse.persistence.enabled', $config['persistence']['enabled'] ?? false);
        $container->setParameter('synapse.persistence.handler', $config['persistence']['handler'] ?? 'session');
        $container->setParameter('synapse.persistence.conversation_class', $config['persistence']['conversation_class'] ?? null);
        $container->setParameter('synapse.persistence.message_class', $config['persistence']['message_class'] ?? null);

        // ── Encryption ────────────────────────────────────────────────────────
        $container->setParameter('synapse.encryption.enabled', $config['encryption']['enabled'] ?? false);
        $container->setParameter('synapse.encryption.key', $config['encryption']['key'] ?? null);

        // ── Token Tracking ────────────────────────────────────────────────────
        $container->setParameter('synapse.token_tracking.enabled', $config['token_tracking']['enabled'] ?? false);
        $container->setParameter('synapse.token_tracking.pricing', $config['token_tracking']['pricing'] ?? []);


        // ── Retention ─────────────────────────────────────────────────────────
        $container->setParameter('synapse.retention.days', $config['retention']['days'] ?? 30);

        // ── Security ──────────────────────────────────────────────────────────
        $container->setParameter('synapse.security.permission_checker', $config['security']['permission_checker'] ?? 'default');
        $container->setParameter('synapse.security.admin_role', $config['security']['admin_role'] ?? 'ROLE_ADMIN');

        // ── Context ───────────────────────────────────────────────────────────
        $container->setParameter('synapse.context.provider', $config['context']['provider'] ?? 'default');
        $container->setParameter('synapse.context.language', $config['context']['language'] ?? 'fr');
        $container->setParameter('synapse.context.base_identity', $config['context']['base_identity'] ?? null);

        // ── Admin ─────────────────────────────────────────────────────────────
        $container->setParameter('synapse.admin.enabled', $config['admin']['enabled'] ?? false);
        $container->setParameter('synapse.admin.route_prefix', $config['admin']['route_prefix'] ?? '/synapse/admin');
        $container->setParameter('synapse.admin.default_color', $config['admin']['default_color'] ?? '#8b5cf6');
        $container->setParameter('synapse.admin.default_icon', $config['admin']['default_icon'] ?? 'robot');

        // ── UI ────────────────────────────────────────────────────────────────
        $container->setParameter('synapse.ui.sidebar_enabled', $config['ui']['sidebar_enabled'] ?? true);
        $container->setParameter('synapse.ui.layout_mode', $config['ui']['layout_mode'] ?? 'standalone');

        // ── Encryption Service ────────────────────────────────────────────────
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

        // ── Repository aliases (doctrine persistence) ─────────────────────────
        if ($config['persistence']['enabled'] && $config['persistence']['handler'] === 'doctrine') {
            if (!empty($config['persistence']['conversation_repository'])) {
                $container->setAlias(
                    ConversationRepository::class,
                    $config['persistence']['conversation_repository']
                )->setPublic(true);
            }
            if (!empty($config['persistence']['message_repository'])) {
                $container->setAlias(
                    MessageRepository::class,
                    $config['persistence']['message_repository']
                )->setPublic(true);
            }
        }

        // ── ConversationManager (si persistence activée avec entités concrètes) ──
        if ($config['persistence']['enabled'] && !empty($config['persistence']['conversation_class'])) {
            $container
                ->register(ConversationManager::class)
                ->setAutowired(true)
                ->setPublic(false)
                ->setArguments([
                    '$conversationRepo'  => null,
                    '$conversationClass' => $config['persistence']['conversation_class'],
                    '$messageClass'      => $config['persistence']['message_class'] ?? null,
                ]);
        }

        // ── Chargement des services ───────────────────────────────────────────
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../../config'));

        // Load core services (always loaded)
        $loader->load('core.yaml');

        // Load admin services (conditionally, if admin is enabled)
        if ($config['admin']['enabled'] ?? false) {
            $loader->load('admin.yaml');
        }

        // ── Auto-configuration (Tags automatiques) ────────────────────────────
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
