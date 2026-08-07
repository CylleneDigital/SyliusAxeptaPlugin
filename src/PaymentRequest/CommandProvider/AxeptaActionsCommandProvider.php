<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandProvider;

use Sylius\Bundle\PaymentBundle\CommandProvider\AbstractServiceCommandProvider;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Routes a payment request to the command matching its action.
 *
 * Without this service tagged for the `axepta` factory, `GatewayFactoryCommandProvider` finds
 * nothing and throws `PaymentRequestNotSupportedException` on the very first payment.
 *
 * @experimental
 *
 * @internal
 */
final class AxeptaActionsCommandProvider extends AbstractServiceCommandProvider
{
    /**
     * @param ServiceProviderInterface<PaymentRequestCommandProviderInterface> $locator
     */
    public function __construct(protected ServiceProviderInterface $locator)
    {
    }

    protected function getCommandProviderIndex(PaymentRequestInterface $paymentRequest): string
    {
        return $paymentRequest->getAction();
    }
}
