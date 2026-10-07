<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sylius\Behat\Context\Ui\Admin\ManagingPaymentMethodsContext;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Context\Setup\AxeptaContext;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Context\Ui\Admin\ManagingAxeptaPaymentMethodContext;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Context\Ui\Shop\AxeptaPaymentContext;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Mocker\AxeptaPaymentPageMocker;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod\AxeptaCreatePage;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod\AxeptaUpdatePage;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->public();

    // Pages: they only add the Axepta configuration fields to the Sylius form.
    $services->set('cyllene_digital_sylius_axepta.behat.page.admin.payment_method.create', AxeptaCreatePage::class)
        ->parent('sylius.behat.page.admin.crud.create')
        ->args(['sylius_admin_payment_method_create']);

    $services->set('cyllene_digital_sylius_axepta.behat.page.admin.payment_method.update', AxeptaUpdatePage::class)
        ->parent('sylius.behat.page.admin.crud.update')
        ->args(['sylius_admin_payment_method_update']);

    $services->set('cyllene_digital_sylius_axepta.behat.mocker.payment_page', AxeptaPaymentPageMocker::class)
        ->args([service('cyllene_digital_sylius_axepta.provider.credentials')]);

    $services->set('cyllene_digital_sylius_axepta.behat.context.setup.axepta', AxeptaContext::class)
        ->args([
            service('sylius.behat.shared_storage'),
            service('sylius.factory.payment_method'),
            service('sylius.factory.gateway_config'),
            service('sylius.repository.payment_method'),
        ]);

    // The Sylius context receives a hard-coded collection of factories, holding only `offline`: it
    // therefore cannot build the creation URL for ours, and opens "/admin/payment-methods/new/" with
    // no parameter - a 404. We redeclare it with the extended collection rather than writing our own
    // creation steps.
    $services->set('cyllene_digital_sylius_axepta.behat.context.ui.admin.managing_payment_methods', ManagingPaymentMethodsContext::class)
        ->args([
            service('cyllene_digital_sylius_axepta.behat.page.admin.payment_method.create'),
            service('sylius.behat.page.admin.payment_method.index'),
            service('cyllene_digital_sylius_axepta.behat.page.admin.payment_method.update'),
            service('sylius.behat.current_page_resolver'),
            [
                'offline' => 'Offline',
                'axepta' => 'Axepta - BNP Paribas',
            ],
        ]);

    $services->set('cyllene_digital_sylius_axepta.behat.context.ui.admin.managing_payment_method', ManagingAxeptaPaymentMethodContext::class)
        ->args([
            service('cyllene_digital_sylius_axepta.behat.page.admin.payment_method.create'),
            service('cyllene_digital_sylius_axepta.behat.page.admin.payment_method.update'),
            service('sylius.behat.current_page_resolver'),
            service('sylius.behat.shared_storage'),
        ]);

    $services->set('cyllene_digital_sylius_axepta.behat.context.ui.shop.payment', AxeptaPaymentContext::class)
        ->args([
            service('sylius.behat.shared_storage'),
            service('cyllene_digital_sylius_axepta.behat.mocker.payment_page'),
            service('kernel'),
            service('sylius.repository.payment_request'),
            service('doctrine.orm.entity_manager'),
            service('sylius.factory.payment_request'),
            service('sylius.announcer.payment_request'),
        ]);
};
