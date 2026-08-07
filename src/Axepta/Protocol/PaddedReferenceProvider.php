<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * Reduces the intended reference to the 12-character `an` format BNP requires.
 *
 * Non-alphanumeric characters are stripped (`REF-2026-000123` → `REF2026000123`), then the **last
 * 12** are kept: on an over-long reference, the discriminating number is at the end, not in the
 * prefix. Padding is done with leading zeroes.
 */
final readonly class PaddedReferenceProvider implements ReferenceProviderInterface
{
    public function provide(PaymentPageRequestContext $context): string
    {
        $reference = (string) preg_replace('/[^A-Za-z0-9]/', '', $context->reference);

        if (\strlen($reference) > self::LENGTH) {
            $reference = substr($reference, -self::LENGTH);
        }

        return str_pad($reference, self::LENGTH, '0', \STR_PAD_LEFT);
    }
}
