<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CylleneDigital\SyliusAxeptaPlugin\Form\Type\AxeptaGatewayConfigurationType;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // The `type` attribute is an invariant: it selects this form for payment methods whose gateway
    // is `axepta`, including those already in the database.
    $services->set('cyllene_digital_sylius_axepta.form.type.gateway_configuration', AxeptaGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => 'axepta', 'label' => 'Axepta - BNP Paribas'])
        ->tag('form.type');
};
