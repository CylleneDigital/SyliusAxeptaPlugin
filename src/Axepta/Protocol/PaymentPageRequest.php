<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * What to post, and where. No notion of HTML: rendering the self-submitting form belongs to the
 * integration layer.
 */
final readonly class PaymentPageRequest
{
    /**
     * @param array<string, string> $fields form fields, values ready to post
     */
    public function __construct(
        public string $url,
        public array $fields,
    ) {
    }

    /**
     * The `TransID` chosen for this payment. The caller must persist it: it is the key that will
     * tie the notification back to the payment.
     */
    public function transactionId(): string
    {
        return $this->fields['TransID'];
    }
}
