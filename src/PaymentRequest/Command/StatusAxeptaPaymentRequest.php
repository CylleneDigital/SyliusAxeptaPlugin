<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command;

use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareInterface;
use Sylius\Bundle\PaymentBundle\Command\PaymentRequestHashAwareTrait;

/**
 * Asks for the current state of the payment to be reported.
 *
 * @experimental Sylius' own `PaymentRequest` foundation is marked `@experimental`: its contract
 *               may change without that being a break of our doing.
 *
 * @internal
 */
final class StatusAxeptaPaymentRequest implements PaymentRequestHashAwareInterface
{
    use PaymentRequestHashAwareTrait;

    public function __construct(?string $hash)
    {
        $this->hash = $hash;
    }
}
