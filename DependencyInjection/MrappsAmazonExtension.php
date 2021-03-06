<?php

namespace Mrapps\AmazonBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

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
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        
        $container->setParameter('mrapps_amazon.parameters.access', $config['parameters']['access']);
        $container->setParameter('mrapps_amazon.parameters.secret', $config['parameters']['secret']);
        $container->setParameter('mrapps_amazon.parameters.region', $config['parameters']['region']);
        $container->setParameter('mrapps_amazon.parameters.default_bucket', $config['parameters']['default_bucket']);
        $container->setParameter('mrapps_amazon.cdn.enable', $config['cdn']['enable']);
        $container->setParameter('mrapps_amazon.cdn.url', $config['cdn']['url']);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');
    }
}
