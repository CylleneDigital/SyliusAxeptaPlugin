<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * `TransID` of the form `sylius-<payment identifier>-<random>`.
 *
 * The payment identifier up front keeps the value readable during bank reconciliation; the 64 bits
 * of randomness guarantee uniqueness, including between two attempts on the same payment, without
 * depending on a counter the caller would have to maintain.
 *
 * The whole fits within the 64 allowed characters: the prefix is truncated if need be, never the
 * random part.
 */
final readonly class PrefixedTransactionIdGenerator implements TransactionIdGeneratorInterface
{
    private const PREFIX = 'sylius-';

    private const RANDOM_BYTES = 8;

    public function generate(PaymentPageRequestContext $context): string
    {
        $random = bin2hex(random_bytes(self::RANDOM_BYTES));
        $available = self::MAX_LENGTH - \strlen(self::PREFIX) - \strlen($random) - 1;

        $identifier = (string) preg_replace('/[^A-Za-z0-9]/', '', $context->paymentIdentifier);

        return self::PREFIX . substr($identifier, 0, $available) . '-' . $random;
    }

    public function resolvePaymentIdentifier(string $transactionId): ?string
    {
        if (!str_starts_with($transactionId, self::PREFIX)) {
            return null;
        }

        // The random part is at the tail, after the last dash; the identifier fills everything
        // between the prefix and it. Splitting therefore happens from the right.
        $withoutPrefix = substr($transactionId, \strlen(self::PREFIX));
        $lastDash = strrpos($withoutPrefix, '-');

        if (false === $lastDash || 0 === $lastDash) {
            return null;
        }

        $identifier = substr($withoutPrefix, 0, $lastDash);

        // `generate()` only ever leaves alphanumerics in the identifier: anything carrying more
        // does not come from here, and is rejected rather than sent to the database.
        //
        // The check is not cosmetic. The value is then read as a primary key, and a database that
        // refuses to compare text with an integer - PostgreSQL does, MySQL does not - throws an
        // exception nothing catches: the notification URL answers 500, and a 500 triggers eight
        // retries from the bank over ~21 h.
        if (!ctype_alnum($identifier)) {
            return null;
        }

        return $identifier;
    }
}
