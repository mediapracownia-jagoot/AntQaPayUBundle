<?php

namespace AntQa\Bundle\PayUBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * Class AntQaPayUBundleExtension
 *
 * @author Piotr Antosik <mail@piotrantosik.com>
 */
class AntQaPayUBundleExtension extends Extension
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $container->setParameter('payu_bundle.payment_class', $config['payment_class']);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlias()
    {
        return 'payu_bundle';
    }
}
