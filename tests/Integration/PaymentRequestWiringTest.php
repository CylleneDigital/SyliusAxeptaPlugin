<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Integration;

use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\CaptureAxeptaPaymentRequest;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\NotifyAxeptaPaymentRequest;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\StatusAxeptaPaymentRequest;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler\CaptureAxeptaPaymentRequestHandler;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler\NotifyAxeptaPaymentRequestHandler;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler\StatusAxeptaPaymentRequestHandler;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandProvider\AxeptaActionsCommandProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider\AxeptaHttpResponseProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider\AxeptaNotifyPaymentProvider;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider\AxeptaNotifyResponseProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Bundle\PaymentBundle\CommandProvider\ServiceProviderAwareCommandProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\ServiceProviderAwareProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 * The `PaymentRequest` path rests entirely on indexed tags. A missing or badly indexed tag shows
 * up neither in static analysis nor at boot: `GatewayFactoryCommandProvider` throws
 * `PaymentRequestNotSupportedException` on the first payment, and not before.
 *
 * The checks go through the behaviour of the components consuming those tags, not through service
 * definitions - which is what the framework does at runtime.
 */
final class PaymentRequestWiringTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function services(): iterable
    {
        yield 'command router' => ['cyllene_digital_sylius_axepta.command_provider.payment_request', AxeptaActionsCommandProvider::class];
        yield 'capture handler' => ['cyllene_digital_sylius_axepta.command_handler.payment_request.capture', CaptureAxeptaPaymentRequestHandler::class];
        yield 'notification handler' => ['cyllene_digital_sylius_axepta.command_handler.payment_request.notify', NotifyAxeptaPaymentRequestHandler::class];
        yield 'status handler' => ['cyllene_digital_sylius_axepta.command_handler.payment_request.status', StatusAxeptaPaymentRequestHandler::class];
        yield 'capture HTTP response' => ['cyllene_digital_sylius_axepta.provider.payment_request.http_response', AxeptaHttpResponseProvider::class];
        yield 'notified payment resolution' => ['cyllene_digital_sylius_axepta.provider.payment_request.notify_payment', AxeptaNotifyPaymentProvider::class];
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
     * Without this entry, `GatewayFactoryCommandProvider` finds nothing for the `axepta` factory
     * and throws `PaymentRequestNotSupportedException`.
     */
    public function testTheGatewayFactoryIsKnownOfTheCommandProviderLocator(): void
    {
        self::bootKernel();

        $provider = self::getContainer()->get('sylius.command_provider.gateway_factory');
        \assert($provider instanceof ServiceProviderAwareCommandProviderInterface);

        self::assertContains('axepta', $provider->getCommandProviderIndexes());
    }

    /** All three actions must be indexed, `notify` included - that is the one taking the money. */
    public function testTheThreeActionsAreIndexed(): void
    {
        self::bootKernel();

        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.command_provider.payment_request');
        \assert($provider instanceof ServiceProviderAwareCommandProviderInterface);

        $indexes = $provider->getCommandProviderIndexes();

        self::assertContains(PaymentRequestInterface::ACTION_CAPTURE, $indexes);
        self::assertContains(PaymentRequestInterface::ACTION_NOTIFY, $indexes);
        self::assertContains(PaymentRequestInterface::ACTION_STATUS, $indexes);
    }

    public function testTheGatewayFactoryIsKnownOfTheHttpResponseProviderLocator(): void
    {
        self::bootKernel();

        $provider = self::getContainer()->get('sylius.provider.payment_request.http_response.gateway_factory');
        \assert($provider instanceof ServiceProviderAwareProviderInterface);

        self::assertContains('axepta', $provider->getProviderIndexes());
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function commands(): iterable
    {
        yield 'capture' => [PaymentRequestInterface::ACTION_CAPTURE, CaptureAxeptaPaymentRequest::class];
        yield 'notify' => [PaymentRequestInterface::ACTION_NOTIFY, NotifyAxeptaPaymentRequest::class];
        yield 'status' => [PaymentRequestInterface::ACTION_STATUS, StatusAxeptaPaymentRequest::class];
    }

    /**
     * @param class-string $expectedCommand
     */
    #[DataProvider('commands')]
    public function testEachActionProducesItsOwnCommand(string $action, string $expectedCommand): void
    {
        self::bootKernel();

        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.command_provider.payment_request');
        \assert($provider instanceof AxeptaActionsCommandProvider);

        self::assertInstanceOf($expectedCommand, $provider->provide($this->paymentRequest($action)));
    }

    /**
     * @param class-string $expectedCommand
     */
    #[DataProvider('commands')]
    public function testEachActionHasItsCommandProvider(string $action, string $expectedCommand): void
    {
        self::bootKernel();

        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.command_provider.payment_request.' . $action);
        \assert($provider instanceof PaymentRequestCommandProviderInterface);

        self::assertTrue($provider->supports($this->paymentRequest($action)));
        self::assertInstanceOf($expectedCommand, $provider->provide($this->paymentRequest($action)));
    }

    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function handledCommands(): iterable
    {
        yield 'capture' => [CaptureAxeptaPaymentRequest::class, CaptureAxeptaPaymentRequestHandler::class];
        yield 'notify' => [NotifyAxeptaPaymentRequest::class, NotifyAxeptaPaymentRequestHandler::class];
        yield 'status' => [StatusAxeptaPaymentRequest::class, StatusAxeptaPaymentRequestHandler::class];
    }

    /**
     * A handler registered on the wrong bus is never called, and nothing reports it.
     *
     * @param class-string $commandClass
     * @param class-string $expectedHandler
     */
    #[DataProvider('handledCommands')]
    public function testTheCommandIsHandledOnThePaymentRequestBus(string $commandClass, string $expectedHandler): void
    {
        self::bootKernel();

        $locator = self::getContainer()->get('sylius.payment_request.command_bus.messenger.handlers_locator');
        \assert($locator instanceof HandlersLocatorInterface);

        $handlers = [];
        foreach ($locator->getHandlers(new Envelope(new $commandClass('a-hash'))) as $handlerDescriptor) {
            // `getHandler()` returns a Closure: the name is what carries the class.
            $handlers[] = $handlerDescriptor->getName();
        }

        self::assertCount(1, $handlers, \sprintf('« %s » doit avoir exactement un handler.', $commandClass));
        self::assertStringStartsWith($expectedHandler, $handlers[0]);
    }

    /**
     * The notification arrives on the payment method's fixed URL: without this provider, the
     * framework does not know which payment to tie it to.
     */
    public function testTheNotifyPaymentProviderIsCollected(): void
    {
        self::bootKernel();

        $composite = self::getContainer()->get('sylius.provider.payment_request.notify_payment');

        $providers = (new \ReflectionProperty($composite, 'paymentNotifyProviders'))->getValue($composite);
        \assert(is_iterable($providers));

        $classes = [];
        foreach ($providers as $provider) {
            self::assertIsObject($provider);
            $classes[] = $provider::class;
        }

        self::assertContains(AxeptaNotifyPaymentProvider::class, $classes);
    }

    /**
     * The framework answers 204; BNP's documentation only cites the 200 as stopping the retries.
     * The decoration only changes the response for our factory.
     */
    public function testTheNotifyResponseProviderIsDecorated(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            AxeptaNotifyResponseProvider::class,
            self::getContainer()->get(NotifyResponseProviderInterface::class),
        );
    }

    private function paymentRequest(string $action): PaymentRequestInterface
    {
        $paymentRequest = $this->createMock(PaymentRequestInterface::class);
        $paymentRequest->method('getAction')->willReturn($action);
        $paymentRequest->method('getId')->willReturn('a-hash');

        return $paymentRequest;
    }
}
