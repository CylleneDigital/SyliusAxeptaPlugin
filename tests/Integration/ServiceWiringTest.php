<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Integration;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestBuilder;
use CylleneDigital\SyliusAxeptaPlugin\Form\Type\AxeptaGatewayConfigurationType;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\CaptureAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\ConvertPaymentAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\NotifyAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\ResolveNextRouteAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\StatusAction;
use CylleneDigital\SyliusAxeptaPlugin\Payum\AxeptaGatewayFactory;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use CylleneDigital\SyliusAxeptaPlugin\Renderer\AutoSubmitFormRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Wiring is the most frequent class of bug on a plugin: it shows up neither in static analysis nor
 * in unit tests, and often not at boot either - only on the first payment.
 */
final class ServiceWiringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function services(): iterable
    {
        yield 'request builder' => ['cyllene_digital_sylius_axepta.protocol.payment_page_request_builder', PaymentPageRequestBuilder::class];
        yield 'notification verifier' => ['cyllene_digital_sylius_axepta.protocol.notification_verifier', NotificationVerifier::class];
        yield 'fournisseur d\'identifiants' => ['cyllene_digital_sylius_axepta.provider.credentials', CredentialsProvider::class];
        yield 'form rendering' => ['cyllene_digital_sylius_axepta.renderer.auto_submit_form', AutoSubmitFormRenderer::class];
        yield 'configuration form' => ['cyllene_digital_sylius_axepta.form.type.gateway_configuration', AxeptaGatewayConfigurationType::class];
    }

    /**
     * @param class-string $expectedClass
     */
    #[DataProvider('services')]
    public function testServiceIsRegistered(string $id, string $expectedClass): void
    {
        self::bootKernel();

        self::assertInstanceOf($expectedClass, self::getContainer()->get($id));
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function payumActions(): iterable
    {
        yield 'convert' => ['cyllene_digital_sylius_axepta.payum.action.convert_payment', ConvertPaymentAction::class];
        yield 'capture' => ['cyllene_digital_sylius_axepta.payum.action.capture', CaptureAction::class];
        yield 'notify' => ['cyllene_digital_sylius_axepta.payum.action.notify', NotifyAction::class];
        yield 'status' => ['cyllene_digital_sylius_axepta.payum.action.status', StatusAction::class];
        yield 'resolve_next_route' => ['cyllene_digital_sylius_axepta.payum.action.resolve_next_route', ResolveNextRouteAction::class];
    }

    /**
     * `ContainerAwareCoreGatewayFactory` resolves actions through `$container->get()`: a private
     * action throws on the first payment, not at boot. Hence checking against the real container
     * rather than the test one, which exposes everything.
     *
     * @param class-string $expectedClass
     */
    #[DataProvider('payumActions')]
    public function testPayumActionIsPublic(string $id, string $expectedClass): void
    {
        $container = self::bootKernel()->getContainer();

        self::assertTrue($container->has($id), \sprintf('Service "%s" must be public.', $id));
        self::assertInstanceOf($expectedClass, $container->get($id));
    }

    /** The factory name is stored in the database: losing it would mute existing methods. */
    public function testGatewayFactoryIsRegisteredUnderItsInvariantName(): void
    {
        self::bootKernel();

        /** @var array<string, string> $factories */
        $factories = self::getContainer()->getParameter('sylius.gateway_factories');

        self::assertArrayHasKey(AxeptaGatewayFactory::NAME, $factories);
    }

    public function testGatewayFactoryBuilderProducesTheFactory(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get('cyllene_digital_sylius_axepta.payum.gateway_factory_builder');
        self::assertIsCallable($builder);

        self::assertInstanceOf(AxeptaGatewayFactory::class, $builder([], new \Payum\Core\CoreGatewayFactory()));
    }

    /** Without `payum.api` the actions would have no credentials and the capture would fail. */
    public function testGatewayFactoryDerivesCredentialsFromTheConfiguration(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get('cyllene_digital_sylius_axepta.payum.gateway_factory_builder');
        self::assertIsCallable($builder);

        /** @var AxeptaGatewayFactory $factory */
        $factory = $builder([], new \Payum\Core\CoreGatewayFactory());

        $config = $factory->createConfig([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 's3cr3t-hmac-key-for-axepta-tests',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]);

        self::assertSame(AxeptaGatewayFactory::NAME, $config['payum.factory_name']);
        self::assertSame(['merchant_id', 'hmac_key', 'blowfish_key'], $config['payum.required_options']);
    }

    public function testPaymentPageUrlIsConfigurable(): void
    {
        self::bootKernel();

        self::assertSame(
            'https://paymentpage.axepta.bnpparibas/payssl.aspx',
            self::getContainer()->getParameter('cyllene_digital_sylius_axepta.payment_page_url'),
        );
    }

    public function testGatewayConfigurationFormIsResolvedForTheAxeptaType(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get('sylius.form_registry.payment_gateway_config');
        \assert($registry instanceof \Sylius\Bundle\ResourceBundle\Form\Registry\FormTypeRegistryInterface);

        self::assertSame(
            AxeptaGatewayConfigurationType::class,
            $registry->get('gateway_config', AxeptaGatewayFactory::NAME),
        );
    }
}
