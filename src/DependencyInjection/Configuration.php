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
 * synpase:
 *    gemini_api_key: '%env(GEMINI_API_KEY)%'
 *    model: 'gemini-2.5-flash-lite'
 *    personas_path: '%kernel.project_dir%/config/personas.json'
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('synapse');

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('api_key')
            ->defaultNull()
            ->info('La clé API Gemini. Si nulle, elle devra être fournie par d\'autres moyens ou via l\'application.')
            ->end()
            ->scalarNode('model')
            ->defaultValue('gemini-2.5-flash-lite')
            ->info('Le modèle Gemini à utiliser pour la génération (défaut: gemini-2.5-flash-lite).')
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
                ->booleanNode('enabled')
                    ->defaultFalse()
                    ->info('Activer Vertex AI au lieu de AI Studio (requis pour thinking natif)')
                ->end()
                ->scalarNode('project_id')
                    ->defaultNull()
                    ->info('Google Cloud Project ID (requis si vertex.enabled=true)')
                ->end()
                ->scalarNode('region')
                    ->defaultValue('europe-west1')
                    ->info('Région Vertex AI (europe-west1, us-central1, etc.)')
                ->end()
                ->scalarNode('service_account_json')
                    ->defaultNull()
                    ->info('Chemin vers le fichier JSON du service account Google Cloud')
                ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
