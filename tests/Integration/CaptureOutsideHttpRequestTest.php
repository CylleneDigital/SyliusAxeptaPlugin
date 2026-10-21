<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Integration;

use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\CaptureAxeptaPaymentRequest;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler\CaptureAxeptaPaymentRequestHandler;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Utils\PaymentScenario;

/**
 * The capture must run inside the customer's request: the redirect page is rendered from what it
 * stores, and its absolute URLs take the host of that request. A `payment_request` transport left
 * asynchronous breaks both, silently - the log is the only place it can show.
 */
final class CaptureOutsideHttpRequestTest extends KernelTestCase
{
    private const MESSAGE = 'Axepta capture handled outside an HTTP request: the payment_request Messenger transport must stay synchronous (sync://, the Sylius default). The customer is not redirected to the bank, and the return and notification URLs do not point at the shop.';

    public function testACaptureHandledByAWorkerIsReportedAsAnError(): void
    {
        $this->capture(withinHttpRequest: false);

        self::assertTrue($this->logs()->hasRecord(self::MESSAGE, Level::Error));
    }

    public function testACaptureWithinTheCustomersRequestIsNotReported(): void
    {
        $this->capture(withinHttpRequest: true);

        self::assertFalse($this->logs()->hasRecord(self::MESSAGE, Level::Error));
    }

    private function capture(bool $withinHttpRequest): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $entityManager = $container->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $scenario = new PaymentScenario($entityManager, $container);
        $payment = $scenario->createPayment($scenario->createPaymentMethod(usePayum: false));
        $paymentMethod = $payment->getMethod();
        \assert(null !== $paymentMethod);

        $paymentRequest = new PaymentRequest($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $entityManager->persist($paymentRequest);
        $scenario->flush();

        if ($withinHttpRequest) {
            $requestStack = $container->get('request_stack');
            \assert($requestStack instanceof RequestStack);
            $requestStack->push(Request::create('https://shop.example/order/pay'));
        }

        $handler = $container->get('cyllene_digital_sylius_axepta.command_handler.payment_request.capture');
        \assert($handler instanceof CaptureAxeptaPaymentRequestHandler);

        $handler(new CaptureAxeptaPaymentRequest((string) $paymentRequest->getHash()));
    }

    private function logs(): TestHandler
    {
        $handler = self::getContainer()->get('monolog.handler.axepta_assertions');
        \assert($handler instanceof TestHandler);

        return $handler;
    }
}
