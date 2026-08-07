<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal
 */
final class CylleneDigitalSyliusAxeptaExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    /**
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        // The configuration tree guarantees both types; the assertion restates them to the analyser.
        \assert(\is_string($config['payment_page_url']) && \is_string($config['logger_channel']));

        $container->setParameter('cyllene_digital_sylius_axepta.payment_page_url', $config['payment_page_url']);
        $container->setParameter('cyllene_digital_sylius_axepta.logger_channel', $config['logger_channel']);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.xml');
    }

    /**
     * Registers the hookables that render the configuration fields in the back office.
     *
     * Testing for the extension avoids forcing `sylius/twig-hooks` on an application that would not
     * have it: without it the plugin stays functional, only the configuration fields do not show.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('sylius_twig_hooks')) {
            return;
        }

        /** @var array{sylius_twig_hooks: array<string, mixed>} $hooks */
        $hooks = Yaml::parseFile(__DIR__ . '/../../config/twig_hooks/admin.yaml');

        $container->prependExtensionConfig('sylius_twig_hooks', $hooks['sylius_twig_hooks']);
    }
}
