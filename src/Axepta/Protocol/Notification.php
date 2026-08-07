<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * Result of verifying an Axepta notification.
 *
 * `$macValid` and `$status` are two distinct pieces of information: a genuine failure notification
 * has a valid MAC and a `FAILED` status. Only {@see self::isPaid()} combines the two, and that is
 * the single question to ask before advancing a payment.
 */
final readonly class Notification
{
    /** Codes through which the platform reports a wrong or missing signature. */
    private const SIGNATURE_ERROR_CODES = ['20100044', '20120044'];

    /**
     * @param array<string, string> $parameters notification fields, keys canonicalised
     */
    public function __construct(
        public bool $macValid,
        public ?AxeptaStatus $status,
        public array $parameters = [],
        /** Only set on a rejected notification. */
        public ?NotificationRejection $rejection = null,
    ) {
    }

    /**
     * A notification that could not be authenticated. No state may be derived from it; the reason
     * only serves logging.
     *
     * @param array<string, string> $parameters
     */
    public static function rejected(NotificationRejection $rejection, array $parameters = []): self
    {
        return new self(false, null, $parameters, $rejection);
    }

    /** Genuine notification **and** accepted payment - the only condition for taking the money. */
    public function isPaid(): bool
    {
        return $this->macValid && true === $this->status?->isSuccessful();
    }

    public function transactionId(): ?string
    {
        return $this->parameters['TransID'] ?? null;
    }

    public function payId(): ?string
    {
        return $this->parameters['PayID'] ?? null;
    }

    /**
     * Is the bank reporting a wrong or missing signature **on our side**?
     *
     * These two codes carry strong operational meaning: the transaction is rejected and **does not
     * appear in the BNP back office**, so it is invisible to a manual review on the bank side. In
     * practice it is almost always a key rotation applied on one side only, rarely an attack -
     * hence a dedicated message rather than a silent rejection.
     *
     * The knowledge sits here, in the protocol layer, not in an adapter: both payment paths must
     * produce the same warning.
     */
    public function signalsSignatureMismatch(): bool
    {
        return \in_array($this->code(), self::SIGNATURE_ERROR_CODES, true);
    }

    /** Identifier covering every step of the transaction: the BNP reconciliation key. */
    public function xid(): ?string
    {
        return $this->parameters['XID'] ?? null;
    }

    /** Eight-digit code; `00000000` means success. Analyse this, never `Description`. */
    public function code(): ?string
    {
        return $this->parameters['Code'] ?? null;
    }

    public function merchantId(): ?string
    {
        return $this->parameters['MerchantID'] ?? null;
    }
}
