<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Protocol;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaddedReferenceProvider;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestContext;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\ReferenceProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaddedReferenceProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function references(): iterable
    {
        yield 'Sylius order number' => ['000000008', '000000000008'];
        yield 'already the right length' => ['123456789012', '123456789012'];
        yield 'separators stripped' => ['REF-2026-000123', 'EF2026000123'];
        yield 'too long, the tail is kept' => ['ABCDEFGHIJKLMNOPQRST', 'IJKLMNOPQRST'];
        yield 'vide' => ['', '000000000000'];
        yield 'espaces seulement' => ['   ', '000000000000'];
    }

    #[DataProvider('references')]
    public function testProducesTwelveCharacters(string $reference, string $expected): void
    {
        $refNr = (new PaddedReferenceProvider())->provide($this->context($reference));

        self::assertSame($expected, $refNr);
        self::assertSame(ReferenceProviderInterface::LENGTH, \strlen($refNr));
    }

    #[DataProvider('references')]
    public function testProducesAlphanumericOnly(string $reference): void
    {
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9]{12}$/',
            (new PaddedReferenceProvider())->provide($this->context($reference)),
        );
    }

    private function context(string $reference): PaymentPageRequestContext
    {
        return new PaymentPageRequestContext(
            amountInCents: 4200,
            currency: 'EUR',
            paymentIdentifier: '42',
            reference: $reference,
            orderDescription: 'Commande',
            successUrl: 'https://shop.test/thank-you',
            failureUrl: 'https://shop.test/order',
            notifyUrl: 'https://shop.test/notify',
        );
    }
}
