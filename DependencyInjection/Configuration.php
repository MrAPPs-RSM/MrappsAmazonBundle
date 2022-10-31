<?php

namespace Mrapps\AmazonBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('mrapps_amazon');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('parameters')
                    ->children()
                        ->scalarNode('access')->defaultValue('')->end()
                        ->scalarNode('secret')->defaultValue('')->end()
                        ->scalarNode('region')->defaultValue('')->end()
                        ->scalarNode('default_bucket')->defaultValue('')->end()
                    ->end()
                ->end()
                ->arrayNode('cdn')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enable')->defaultValue(false)->end()
                        ->scalarNode('url')->defaultValue('')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
