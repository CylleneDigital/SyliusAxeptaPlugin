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
 * The keys must appear neither in a dump, nor in an exception message, nor in a log. Private
 * properties do not achieve that: `dump()`, `var_dump()` and `print_r()` print them. The keys are
 * therefore held in `\SensitiveParameterValue`, which PHP itself never displays and refuses to
 * serialise, and the constructor marks them `#[\SensitiveParameter]` so that exception traces mask
 * them too. No `__debugInfo()`: the class exposes no representation of its own.
 *
 * Validation happens at construction time: an empty field fails here, not in the middle of a
 * payment.
 */
final readonly class AxeptaCredentials
{
    public const DEFAULT_PAYMENT_PAGE_URL = 'https://paymentpage.axepta.bnpparibas/payssl.aspx';

    private \SensitiveParameterValue $hmacKey;

    private \SensitiveParameterValue $blowfishKey;

    public function __construct(
        public string $merchantId,
        #[\SensitiveParameter]
        string $hmacKey,
        #[\SensitiveParameter]
        string $blowfishKey,
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

        $this->hmacKey = new \SensitiveParameterValue($hmacKey);
        $this->blowfishKey = new \SensitiveParameterValue($blowfishKey);
    }

    public function signer(): HmacSigner
    {
        return new HmacSigner(self::reveal($this->hmacKey));
    }

    public function cipher(): BlowfishEcbInterface
    {
        return ($this->cipherFactory ?? new BlowfishEcbFactory())->create(self::reveal($this->blowfishKey));
    }

    private static function reveal(\SensitiveParameterValue $wrapped): string
    {
        $value = $wrapped->getValue();
        \assert(\is_string($value));

        return $value;
    }
}
