<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcbFactory;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcbFactoryInterface;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcbInterface;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\HmacSigner;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\InvalidCredentialsException;

/**
 * Merchant identifier and keys of an Axepta gateway.
 *
 * Immutable, and deliberately without `__toString()` or `__debugInfo()`: the keys must appear
 * neither in a `dump()`, nor in an exception message, nor in a log. They are marked
 * `#[\SensitiveParameter]` so that PHP masks them in exception traces.
 *
 * Validation happens at construction time: an empty field fails here, not in the middle of a
 * payment.
 */
final readonly class AxeptaCredentials
{
    public const DEFAULT_PAYMENT_PAGE_URL = 'https://paymentpage.axepta.bnpparibas/payssl.aspx';

    public function __construct(
        public string $merchantId,
        #[\SensitiveParameter]
        private string $hmacKey,
        #[\SensitiveParameter]
        private string $blowfishKey,
        public string $paymentPageUrl = self::DEFAULT_PAYMENT_PAGE_URL,
        private ?BlowfishEcbFactoryInterface $cipherFactory = null,
    ) {
        if ('' === $merchantId) {
            throw InvalidCredentialsException::emptyField('merchant_id');
        }
        if ('' === $hmacKey) {
            throw InvalidCredentialsException::emptyField('hmac_key');
        }
        if ('' === $blowfishKey) {
            throw InvalidCredentialsException::emptyField('blowfish_key');
        }
        if ('' === $paymentPageUrl) {
            throw InvalidCredentialsException::emptyField('payment_page_url');
        }
    }

    public function signer(): HmacSigner
    {
        return new HmacSigner($this->hmacKey);
    }

    public function cipher(): BlowfishEcbInterface
    {
        return ($this->cipherFactory ?? new BlowfishEcbFactory())->create($this->blowfishKey);
    }
}
