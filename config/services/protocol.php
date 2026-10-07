<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcbFactory;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcbFactoryInterface;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaddedReferenceProvider;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestBuilder;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PrefixedTransactionIdGenerator;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\ReferenceProviderInterface;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;

// Protocol layer: no dependency on Sylius nor on Payum.
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('cyllene_digital_sylius_axepta.crypto.blowfish_factory', BlowfishEcbFactory::class);
    $services->alias(BlowfishEcbFactoryInterface::class, 'cyllene_digital_sylius_axepta.crypto.blowfish_factory');

    $services->set('cyllene_digital_sylius_axepta.protocol.transaction_id_generator', PrefixedTransactionIdGenerator::class);
    $services->alias(TransactionIdGeneratorInterface::class, 'cyllene_digital_sylius_axepta.protocol.transaction_id_generator');

    $services->set('cyllene_digital_sylius_axepta.protocol.reference_provider', PaddedReferenceProvider::class);
    $services->alias(ReferenceProviderInterface::class, 'cyllene_digital_sylius_axepta.protocol.reference_provider');

    $services->set('cyllene_digital_sylius_axepta.protocol.payment_page_request_builder', PaymentPageRequestBuilder::class)
        ->args([
            service(TransactionIdGeneratorInterface::class),
            service(ReferenceProviderInterface::class),
        ]);

    $services->set('cyllene_digital_sylius_axepta.protocol.notification_verifier', NotificationVerifier::class);

    $services->set('cyllene_digital_sylius_axepta.provider.credentials', CredentialsProvider::class)
        ->args([
            service(BlowfishEcbFactoryInterface::class),
            param('cyllene_digital_sylius_axepta.payment_page_url'),
        ]);
};
