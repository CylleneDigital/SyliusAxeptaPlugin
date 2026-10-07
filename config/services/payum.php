<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\CaptureAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\ConvertPaymentAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\NotifyAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\ResolveNextRouteAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\StatusAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\AxeptaGatewayFactoryBuilder;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;

return static function (ContainerConfigurator $container): void {
    // `ContainerAwareCoreGatewayFactory` resolves the actions through `$container->get()`: they must
    // be public. A private action does not break at boot, it breaks on the first payment.
    $services = $container->services()->defaults()->public();

    $services->set('cyllene_digital_sylius_axepta.payum.gateway_factory_builder', AxeptaGatewayFactoryBuilder::class)
        ->args([service('cyllene_digital_sylius_axepta.provider.credentials')])
        ->tag('payum.gateway_factory_builder', ['factory' => 'axepta']);

    $services->set('cyllene_digital_sylius_axepta.payum.action.convert_payment', ConvertPaymentAction::class)
        ->tag('payum.action', ['factory' => 'axepta', 'alias' => 'cyllene_digital_sylius_axepta.convert_payment']);

    $services->set('cyllene_digital_sylius_axepta.payum.action.capture', CaptureAction::class)
        ->args([
            service('payum'),
            service('cyllene_digital_sylius_axepta.provider.return_url'),
            service('cyllene_digital_sylius_axepta.protocol.payment_page_request_builder'),
            service(PaymentDescriptionProviderInterface::class),
            service('cyllene_digital_sylius_axepta.renderer.auto_submit_form'),
            service('logger'),
        ])
        ->tag('payum.action', ['factory' => 'axepta', 'alias' => 'cyllene_digital_sylius_axepta.capture'])
        ->tag('monolog.logger', ['channel' => '%cyllene_digital_sylius_axepta.logger_channel%']);

    $services->set('cyllene_digital_sylius_axepta.payum.action.notify', NotifyAction::class)
        ->args([
            service('request_stack'),
            service(StateMachineInterface::class),
            service('cyllene_digital_sylius_axepta.protocol.notification_verifier'),
            service('logger'),
        ])
        ->tag('payum.action', ['factory' => 'axepta', 'alias' => 'cyllene_digital_sylius_axepta.notify'])
        ->tag('monolog.logger', ['channel' => '%cyllene_digital_sylius_axepta.logger_channel%']);

    $services->set('cyllene_digital_sylius_axepta.payum.action.status', StatusAction::class)
        ->tag('payum.action', ['factory' => 'axepta', 'alias' => 'cyllene_digital_sylius_axepta.status']);

    $services->set('cyllene_digital_sylius_axepta.payum.action.resolve_next_route', ResolveNextRouteAction::class)
        ->tag('payum.action', ['factory' => 'axepta', 'alias' => 'cyllene_digital_sylius_axepta.resolve_next_route']);
};
