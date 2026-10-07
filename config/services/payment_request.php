<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler\CaptureAxeptaPaymentRequestHandler;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler\NotifyAxeptaPaymentRequestHandler;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler\StatusAxeptaPaymentRequestHandler;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandProvider\AxeptaActionsCommandProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandProvider\CaptureCommandProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandProvider\NotifyCommandProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandProvider\StatusCommandProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider\AxeptaHttpResponseProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider\AxeptaNotifyPaymentProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider\AxeptaNotifyResponseProvider;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;

/*
 * PaymentRequest path (`usePayum = false`). The matching foundation is marked `@experimental` by
 * Sylius: these services may have to follow a contract change without that being a compatibility
 * break of our doing.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyllene_digital_sylius_axepta.command_provider.payment_request', AxeptaActionsCommandProvider::class)
        ->args([tagged_locator('cyllene_digital_sylius_axepta.command_provider.payment_request', indexAttribute: 'action')])
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => 'axepta']);

    $services->set('cyllene_digital_sylius_axepta.command_provider.payment_request.capture', CaptureCommandProvider::class)
        ->tag('cyllene_digital_sylius_axepta.command_provider.payment_request', ['action' => 'capture']);

    $services->set('cyllene_digital_sylius_axepta.command_provider.payment_request.notify', NotifyCommandProvider::class)
        ->tag('cyllene_digital_sylius_axepta.command_provider.payment_request', ['action' => 'notify']);

    $services->set('cyllene_digital_sylius_axepta.command_provider.payment_request.status', StatusCommandProvider::class)
        ->tag('cyllene_digital_sylius_axepta.command_provider.payment_request', ['action' => 'status']);

    $services->set('cyllene_digital_sylius_axepta.command_handler.payment_request.capture', CaptureAxeptaPaymentRequestHandler::class)
        ->args([
            service('sylius.provider.payment_request'),
            service('cyllene_digital_sylius_axepta.provider.credentials'),
            service('cyllene_digital_sylius_axepta.protocol.payment_page_request_builder'),
            service(PaymentDescriptionProviderInterface::class),
            service('sylius_abstraction.state_machine'),
            service('router'),
            service('cyllene_digital_sylius_axepta.provider.return_url'),
            service('logger'),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => '%cyllene_digital_sylius_axepta.logger_channel%']);

    $services->set('cyllene_digital_sylius_axepta.command_handler.payment_request.notify', NotifyAxeptaPaymentRequestHandler::class)
        ->args([
            service('sylius.provider.payment_request'),
            service('cyllene_digital_sylius_axepta.provider.credentials'),
            service('cyllene_digital_sylius_axepta.protocol.notification_verifier'),
            service('sylius_abstraction.state_machine'),
            service('sylius_payum.resolver.payment_request.doctrine_proxy_object'),
            service('logger'),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => '%cyllene_digital_sylius_axepta.logger_channel%']);

    $services->set('cyllene_digital_sylius_axepta.command_handler.payment_request.status', StatusAxeptaPaymentRequestHandler::class)
        ->args([
            service('sylius.provider.payment_request'),
            service('sylius_abstraction.state_machine'),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus']);

    $services->set('cyllene_digital_sylius_axepta.provider.payment_request.http_response', AxeptaHttpResponseProvider::class)
        ->args([service('cyllene_digital_sylius_axepta.renderer.auto_submit_form')])
        ->tag('sylius.payment_request.provider.http_response', ['gateway_factory' => 'axepta']);

    // Explicit priority: in the test environment Sylius registers a `DummyNotifyPaymentProvider`
    // whose `supports()` answers true to everything and which returns the oldest payment in the
    // database. Without a priority it captures our notifications and ties them to the wrong payment -
    // silently, since the response stays 200.
    $services->set('cyllene_digital_sylius_axepta.provider.payment_request.notify_payment', AxeptaNotifyPaymentProvider::class)
        ->args([
            service('sylius.repository.payment'),
            service('cyllene_digital_sylius_axepta.provider.credentials'),
            service('cyllene_digital_sylius_axepta.protocol.notification_verifier'),
            service(TransactionIdGeneratorInterface::class),
            service('sylius.provider.payment_request.gateway_factory_name'),
            'axepta',
        ])
        ->tag('sylius.payment_request.payment_notify_provider', ['priority' => 10]);

    // Decoration: the other gateways of the host application keep their response.
    $services->set('cyllene_digital_sylius_axepta.provider.payment_request.notify_response', AxeptaNotifyResponseProvider::class)
        ->decorate('sylius.provider.payment_request.notify_response')
        ->args([
            service('.inner'),
            service('sylius.provider.payment_request.gateway_factory_name'),
            'axepta',
        ]);
};
