<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto;

/**
 * HMAC-SHA256 signature of the Axepta protocol (the `MAC` field).
 *
 * The signed message is the ordered concatenation of field values separated by `*`: a missing value
 * becomes an empty string but the separator stays - `implode('*', ...)` reproduces exactly that
 * behaviour, as documented by BNP.
 *
 * Request fields (outgoing)     : PayID, TransID, MerchantID, Amount, Currency.
 * Response fields (notification): PayID, TransID, MerchantID, Status, Code.
 */
final readonly class HmacSigner
{
    public function __construct(#[\SensitiveParameter] private string $secretKey)
    {
    }

    /**
     * @param list<string> $values ordered values of the fields to sign
     */
    public function sign(array $values): string
    {
        return hash_hmac('sha256', implode('*', $values), $this->secretKey);
    }

    /**
     * Verifies a MAC in constant time, against timing attacks.
     *
     * BNP returns the MAC in **uppercase** hexadecimal whereas `hash_hmac` produces lowercase, so we
     * normalise before comparing. Hexadecimal case carries no security information.
     *
     * @param list<string> $values
     */
    public function verify(array $values, string $mac): bool
    {
        return hash_equals($this->sign($values), mb_strtolower($mac));
    }
}
