<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider;

use Sylius\Bundle\PaymentBundle\Provider\GatewayFactoryNameProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\NotifyResponseProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers **200** to Axepta notifications, where the framework answers 204.
 *
 * BNP's documentation establishes that a 404 or a 500 triggers 8 retries spread over ~21 h 36,
 * without ever writing that a 204 stops them - only the 200 is mentioned, and BNP confirmed in
 * writing that it does stop them. A 204 would remain a gamble.
 *
 * The service decorates Sylius' own and only changes the response for our factory: other gateways
 * of the host application keep their behaviour.
 *
 * @experimental
 *
 * @internal
 */
final readonly class AxeptaNotifyResponseProvider implements NotifyResponseProviderInterface
{
    public function __construct(
        private NotifyResponseProviderInterface $decorated,
        private GatewayFactoryNameProviderInterface $gatewayFactoryNameProvider,
        private string $gatewayFactoryName,
    ) {
    }

    public function provide(PaymentRequestInterface $paymentRequest): Response
    {
        if ($this->gatewayFactoryName !== $this->gatewayFactoryNameProvider->provideFromPaymentRequest($paymentRequest)) {
            return $this->decorated->provide($paymentRequest);
        }

        return new Response('OK', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
