<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Asks for the payment request to be built and the customer redirected.
 *
 * @experimental Sylius' own `PaymentRequest` foundation is marked `@experimental`: its contract
 *               may change without that being a break of our doing.
 *
 * @internal
 */
final class CaptureAxeptaPaymentRequest implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
