<?php

namespace Mrapps\AmazonBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class MrappsAmazonExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition('mrapps.amazon.s3');

        $definition->replaceArgument(2, $config['parameters']['access']);
        $definition->replaceArgument(3, $config['parameters']['secret']);
        $definition->replaceArgument(4, $config['parameters']['region']);
        $definition->replaceArgument(5, $config['parameters']['default_bucket']);
        $definition->replaceArgument(6, $config['cdn']['enable']);
        $definition->replaceArgument(7, $config['cdn']['url']);
    }
}
