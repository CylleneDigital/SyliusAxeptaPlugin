<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Provider;

use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The customer's return URL, and the signature protecting it.
 *
 * The URL does not carry the order token: it has no business travelling to a third party, and its
 * alphabet (`~`, `_`, `-`) has no place in a field whose documentation does not state the accepted
 * characters. The payment identifier, for its part, is an integer - hence guessable: it is paired
 * with a hexadecimal signature derived from the application secret.
 *
 * @internal
 */
final readonly class ReturnUrlProvider
{
    private const ROUTE = 'cyllene_digital_sylius_axepta_shop_return';

    /** 16 bytes: enough to make an exhaustive search pointless, without lengthening the URL. */
    private const SIGNATURE_LENGTH = 32;

    /** Held like the gateway keys: a private string property is printed by `dump()`. */
    private \SensitiveParameterValue $secret;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[\SensitiveParameter]
        string $secret,
    ) {
        $this->secret = new \SensitiveParameterValue($secret);
    }

    public function forPayment(PaymentInterface $payment): string
    {
        $id = $payment->getId();
        \assert(\is_scalar($id));

        $paymentId = (string) $id;

        return $this->urlGenerator->generate(
            self::ROUTE,
            ['paymentId' => $paymentId, 'signature' => $this->sign($paymentId)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function isSignatureValid(string $paymentId, string $signature): bool
    {
        return hash_equals($this->sign($paymentId), $signature);
    }

    private function sign(string $paymentId): string
    {
        $secret = $this->secret->getValue();
        \assert(\is_string($secret));

        return substr(hash_hmac('sha256', self::ROUTE . ':' . $paymentId, $secret), 0, self::SIGNATURE_LENGTH);
    }
}
