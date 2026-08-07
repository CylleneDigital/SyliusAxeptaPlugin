<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

use Random\RandomException;

/**
 * Produces the `TransID` of a payment.
 *
 * BNP contract: type `ans`, **64 characters maximum**, unique per payment. A collision gets the
 * transaction refused or, worse, ties a notification to the wrong payment - uniqueness is not
 * negotiable.
 *
 * Substitutable: an integrator with their own bank reconciliation conventions replaces the service
 * with their own.
 */
interface TransactionIdGeneratorInterface
{
    public const MAX_LENGTH = 64;

    /**
     * A generator drawing randomness is entitled to fail if the system entropy source is
     * unavailable. The caller does not catch: better not to start a payment at all than to start
     * one with a predictable `TransID`.
     *
     * @throws RandomException
     */
    public function generate(PaymentPageRequestContext $context): string;

    /**
     * The inverse operation: which payment does this `TransID` come from?
     *
     * It is the only link between a notification and its payment when the notification URL is
     * shared across the payment method - the case of the `PaymentRequest` path, where it carries
     * the method code rather than a token specific to the payment.
     *
     * It is part of the contract, not a convention guessed by the caller: **substituting the
     * generator requires supplying its inverse.** Failing that, no notification will ever be tied
     * to its payment, and the bank will retry eight times over ~21 h before giving up on an order
     * the customer has nonetheless paid for.
     *
     * Returns `null` when the `TransID` plainly does not come from this generator - a message from
     * another merchant, from an earlier integration, or plain noise.
     */
    public function resolvePaymentIdentifier(string $transactionId): ?string;
}
