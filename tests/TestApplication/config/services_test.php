<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $container) {
    if (str_starts_with($container->env(), 'test')) {
        // Sylius 2.3 ships its Behat services in PHP; 2.1 and 2.2 only in XML, which Symfony 8 cannot load.
        $syliusBehatServices = dirname(__DIR__, 3) . '/vendor/sylius/sylius/src/Sylius/Behat/Resources/config/services';
        $container->import(is_file($syliusBehatServices . '.php') ? $syliusBehatServices . '.php' : $syliusBehatServices . '.xml');
        $container->import('@CylleneDigitalSyliusAxeptaPlugin/tests/Behat/Resources/services.php');
    }
};
