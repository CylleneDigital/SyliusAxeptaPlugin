<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandProvider;

use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\CaptureAxeptaPaymentRequest;
use Sylius\Bundle\PaymentBundle\CommandProvider\PaymentRequestCommandProviderInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

/**
 * @experimental
 *
 * @internal
 */
final class CaptureCommandProvider implements PaymentRequestCommandProviderInterface
{
    public function supports(PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::ACTION_CAPTURE === $paymentRequest->getAction();
    }

    public function provide(PaymentRequestInterface $paymentRequest): object
    {
        return new CaptureAxeptaPaymentRequest($paymentRequest->getId());
    }
}
