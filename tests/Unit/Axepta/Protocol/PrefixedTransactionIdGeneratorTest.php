<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Protocol;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestContext;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PrefixedTransactionIdGenerator;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrefixedTransactionIdGeneratorTest extends TestCase
{
    public function testKeepsThePaymentIdentifierReadable(): void
    {
        $transactionId = (new PrefixedTransactionIdGenerator())->generate($this->context('42'));

        self::assertStringStartsWith('sylius-42-', $transactionId);
    }

    /**
     * Collision is the real risk: it gets the transaction refused or, worse, ties a notification
     * to the wrong payment. 10,000 generations on the **same** payment, which is the worst case -
     * retries.
     */
    public function testDoesNotCollide(): void
    {
        $generator = new PrefixedTransactionIdGenerator();
        $context = $this->context('42');

        $generated = [];
        for ($i = 0; $i < 10000; ++$i) {
            $generated[$generator->generate($context)] = true;
        }

        self::assertCount(10000, $generated);
    }

    public function testStaysWithinTheDocumentedLength(): void
    {
        $transactionId = (new PrefixedTransactionIdGenerator())->generate(
            $this->context(str_repeat('9', 200)),
        );

        self::assertLessThanOrEqual(TransactionIdGeneratorInterface::MAX_LENGTH, \strlen($transactionId));
    }

    /** Type `ans`: we stick to alphanumerics and the dash, which appears in BNP's examples. */
    public function testProducesAnAllowedCharacterSet(): void
    {
        $transactionId = (new PrefixedTransactionIdGenerator())->generate(
            $this->context('payment #42 / reference'),
        );

        self::assertMatchesRegularExpression('/^[A-Za-z0-9-]+$/', $transactionId);
    }

    private function context(string $paymentIdentifier): PaymentPageRequestContext
    {
        return new PaymentPageRequestContext(
            amountInCents: 4200,
            currency: 'EUR',
            paymentIdentifier: $paymentIdentifier,
            reference: '000000008',
            orderDescription: 'Order',
            successUrl: 'https://shop.test/thank-you',
            failureUrl: 'https://shop.test/order',
            notifyUrl: 'https://shop.test/notify',
        );
    }

    /**
     * The inverse operation is part of the contract: it is how a notification arriving on the
     * payment method's shared URL finds its payment, by primary key.
     */
    public function testResolvesThePaymentItWasGeneratedFor(): void
    {
        $generator = new PrefixedTransactionIdGenerator();
        $transactionId = $generator->generate($this->context('4242'));

        self::assertSame('4242', $generator->resolvePaymentIdentifier($transactionId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignTransactionIds(): iterable
    {
        yield 'foreign prefix' => ['autre-4242-0123456789abcdef'];
        yield 'no random part' => ['sylius-4242'];
        yield 'empty identifier' => ['sylius--0123456789abcdef'];
        yield 'empty' => [''];

        // Splitting happens on the last dash: unchecked, this one would yield "4242-suite",
        // which `generate()` cannot have produced since it only leaves alphanumerics there.
        yield 'identifier carrying a dash' => ['sylius-4242-suite-0123456789abcdef'];
        yield 'non-alphanumeric identifier' => ['sylius-42_42-0123456789abcdef'];
    }

    /**
     * A message from another merchant, from an earlier integration, or plain noise.
     *
     * Rejecting is not merely a matter of tidiness: the returned value is read as a primary key,
     * and a database refusing to compare text with an integer - PostgreSQL does, MySQL does not -
     * then throws an exception nothing catches. The notification URL would answer 500, and a 500
     * triggers eight retries from the bank over ~21 h.
     */
    #[DataProvider('foreignTransactionIds')]
    public function testDoesNotResolveAForeignTransactionId(string $transactionId): void
    {
        self::assertNull((new PrefixedTransactionIdGenerator())->resolvePaymentIdentifier($transactionId));
    }
}
