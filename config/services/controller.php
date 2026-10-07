<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CylleneDigital\SyliusAxeptaPlugin\Controller\ReturnFromPaymentPageAction;
use CylleneDigital\SyliusAxeptaPlugin\Provider\ReturnUrlProvider;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyllene_digital_sylius_axepta.provider.return_url', ReturnUrlProvider::class)
        ->args([
            service('router'),
            param('kernel.secret'),
        ]);

    $services->set(ReturnFromPaymentPageAction::class, ReturnFromPaymentPageAction::class)
        ->public()
        ->args([
            service('sylius.repository.payment'),
            service('cyllene_digital_sylius_axepta.provider.return_url'),
            service('router'),
        ])
        ->tag('controller.service_arguments');
};
