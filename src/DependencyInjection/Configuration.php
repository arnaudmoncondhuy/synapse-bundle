<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('synapse');

        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('gemini_api_key')
            ->isRequired()
            ->cannotBeEmpty()
            ->info('Your Google Gemini API key.')
            ->end()
            ->scalarNode('model')
            ->defaultValue('gemini-2.0-flash')
            ->info('The Gemini model to use for generation.')
            ->end()
            ->scalarNode('personas_path')
            ->defaultNull()
            ->info('Path to your custom personas.json file. If null, it will use the bundle default.')
            ->end()
            ->end();

        return $treeBuilder;
    }
}
