<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Mocker;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\HmacSigner;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Sylius\Component\Core\Model\PaymentMethodInterface;

/**
 * Plays the bank during a scenario.
 *
 * There is nothing to intercept on the outgoing side: the gateway is off-site, and it is the
 * customer's browser that posts to the hosted payment page. The only flow to simulate is
 * **incoming** - the server-to-server notification - and it is forged with the payment method
 * keys.
 */
final readonly class AxeptaPaymentPageMocker
{
    public function __construct(private CredentialsProvider $credentialsProvider)
    {
    }

    /**
     * A notification as the bank emits it: only `Data` travels, in hexadecimal.
     *
     * @return array<string, string>
     */
    public function notification(
        PaymentMethodInterface $paymentMethod,
        string $transactionId,
        string $status = 'OK',
        string $code = '00000000',
    ): array {
        $credentials = $this->credentialsProvider->fromPaymentMethod($paymentMethod);
        $hmacKey = $this->hmacKeyOf($paymentMethod);
        $payId = 'PAY-' . $transactionId;

        $payload = \sprintf(
            'PayID=%s&TransID=%s&MerchantID=%s&Status=%s&Code=%s&MAC=%s',
            $payId,
            $transactionId,
            $credentials->merchantId,
            $status,
            $code,
            strtoupper((new HmacSigner($hmacKey))->sign([
                $payId,
                $transactionId,
                $credentials->merchantId,
                $status,
                $code,
            ])),
        );

        return ['Data' => bin2hex($credentials->cipher()->encrypt(
            (string) mb_convert_encoding($payload, 'ISO-8859-1', 'UTF-8'),
        ))];
    }

    /**
     * A notification whose signature does not match the content: what someone trying to declare
     * themselves paid without knowing the key would produce.
     *
     * @return array<string, string>
     */
    public function forgedNotification(PaymentMethodInterface $paymentMethod, string $transactionId): array
    {
        $credentials = $this->credentialsProvider->fromPaymentMethod($paymentMethod);

        $payload = \sprintf(
            'PayID=PAY-forged&TransID=%s&MerchantID=%s&Status=OK&Code=00000000&MAC=%s',
            $transactionId,
            $credentials->merchantId,
            str_repeat('0', 64),
        );

        return ['Data' => bin2hex($credentials->cipher()->encrypt($payload))];
    }

    private function hmacKeyOf(PaymentMethodInterface $paymentMethod): string
    {
        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];
        $hmacKey = $config['hmac_key'] ?? '';

        return \is_string($hmacKey) ? $hmacKey : '';
    }
}
