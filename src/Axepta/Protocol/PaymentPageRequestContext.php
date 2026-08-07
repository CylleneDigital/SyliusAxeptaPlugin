<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * Input data of a payment request, expressed in business terms.
 *
 * Neither `TransID` nor `RefNr` appear here: those are protocol values, produced from this context
 * by {@see TransactionIdGeneratorInterface} and {@see ReferenceProviderInterface}, both
 * substitutable.
 */
final readonly class PaymentPageRequestContext
{
    public function __construct(
        /** Amount in the smallest unit of the currency, cents for the euro. */
        public int $amountInCents,
        /** ISO 4217 code. */
        public string $currency,
        /** Stable identifier of the payment on the shop side - the basis for the `TransID`. */
        public string $paymentIdentifier,
        /** Intended merchant reference - the basis for the `RefNr`. */
        public string $reference,
        /** Description displayed on the payment page. */
        public string $orderDescription,
        public string $successUrl,
        public string $failureUrl,
        public string $notifyUrl,
        /** Target of the "Cancel" button on the BNP page. */
        public ?string $backUrl = null,
        /** Order locale, such as `fr_FR`; the builder maps it to the expected `Language` code. */
        public ?string $locale = null,
        /** Forces `OrderDesc` to `Test:0000`, as the BNP demonstration account requires. */
        public bool $testMode = false,
        /**
         * `TransID` already assigned to this payment, to be reused as is.
         *
         * A customer reloading the redirect page replays the capture: without this, every reload
         * would open one more transaction at BNP for the same payment.
         */
        public ?string $transactionId = null,
    ) {
    }
}
