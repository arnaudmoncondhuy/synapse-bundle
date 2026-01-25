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
            ->end();

        return $treeBuilder;
    }
}
