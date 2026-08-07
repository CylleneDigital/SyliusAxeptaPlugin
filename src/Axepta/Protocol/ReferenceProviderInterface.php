<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * Produces the `RefNr` of a payment.
 *
 * BNP contract: type `an`, **exactly 12 characters**, left-padded with zeroes. Some payment means
 * additionally require it to be unique.
 *
 * Substitutable: this is the field an integrator sees in their BNP back office, so they must be
 * able to put whatever suits them there - order number, external identifier, accounting reference.
 */
interface ReferenceProviderInterface
{
    public const LENGTH = 12;

    public function provide(PaymentPageRequestContext $context): string;
}
