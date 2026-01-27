<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Définition de l'arbre de configuration du Bundle.
 *
 * Ce fichier valide et documente les options disponibles dans le fichier `config/packages/synapse.yaml`.
 *
 * Exemple de configuration attendue :
 * synapse:
 *    model: 'gemini-2.5-flash'
 *    vertex:
 *        project_id: 'your-gcp-project-id'
 *        region: 'europe-west1'
 *        service_account_json: '%kernel.project_dir%/config/secrets/gcp-service-account.json'
 *    thinking:
 *        enabled: true
 *        budget: 2048
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('synapse');

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('model')
            ->defaultValue('gemini-2.5-flash')
            ->info('Le modèle Gemini à utiliser via Vertex AI (défaut: gemini-2.5-flash).')
            ->end()
            ->scalarNode('personas_path')
            ->defaultNull()
            ->info('Chemin absolu vers votre fichier personas.json personnalisé. Si null, utilise le fichier par défaut du bundle.')
            ->end()
            ->arrayNode('thinking')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Activer le mode thinking natif de Gemini (améliore le debug)')
                ->end()
                ->integerNode('budget')
                    ->defaultValue(1024)
                    ->min(0)
                    ->max(24576)
                    ->info('Budget de tokens pour le thinking (0 = désactivé si supporté par le modèle)')
                ->end()
            ->end()
            ->end()
            ->arrayNode('vertex')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('project_id')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Google Cloud Project ID (obligatoire)')
                ->end()
                ->scalarNode('region')
                    ->defaultValue('europe-west1')
                    ->info('Région Vertex AI (europe-west1, us-central1, etc.)')
                ->end()
                ->scalarNode('service_account_json')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Chemin vers le fichier JSON du service account Google Cloud (obligatoire)')
                ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
