<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Utils;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\HmacSigner;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;

/**
 * Forges genuine notifications from the test keys.
 *
 * This is how failure cases are exercised - refusal, tampered signature, replay - without depending
 * on the platform. The forger reuses the plugin's own primitives: that is deliberate, what is
 * tested here is the integration logic, the cryptography being covered by unit tests against
 * external vectors.
 */
final readonly class AxeptaNotificationForger
{
    public function __construct(private AxeptaCredentials $credentials, private string $hmacKey)
    {
    }

    /**
     * A notification as BNP sends it: the body only carries `Data`, in hexadecimal.
     *
     * @param array<string, string> $extra extra fields of the encrypted payload
     *
     * @return array<string, string>
     */
    public function forge(
        string $transactionId,
        string $status = 'OK',
        string $code = '00000000',
        string $payId = 'PAY-1234567890',
        array $extra = [],
    ): array {
        return ['Data' => $this->encrypt($this->payload($transactionId, $status, $code, $payId, $extra))];
    }

    /**
     * A notification whose signature no longer matches the content: what a middleman able to
     * replay a message without knowing the key would produce.
     *
     * @return array<string, string>
     */
    public function forgeWithTamperedStatus(string $transactionId): array
    {
        $payload = str_replace(
            'Status=FAILED',
            'Status=OK',
            $this->payload($transactionId, 'FAILED', '22720040', 'PAY-1234567890'),
        );

        return ['Data' => $this->encrypt($payload)];
    }

    /**
     * @param array<string, string> $extra
     */
    private function payload(string $transactionId, string $status, string $code, string $payId, array $extra = []): string
    {
        $fields = [
            'PayID' => $payId,
            'TransID' => $transactionId,
            'MerchantID' => $this->credentials->merchantId,
            'Status' => $status,
            'Code' => $code,
            // The platform publishes its signatures in uppercase hexadecimal.
            'MAC' => strtoupper((new HmacSigner($this->hmacKey))->sign([
                $payId,
                $transactionId,
                $this->credentials->merchantId,
                $status,
                $code,
            ])),
        ] + $extra;

        $pairs = [];
        foreach ($fields as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('&', $pairs);
    }

    /** The platform emits in ISO-8859-1: a forged notification must be too. */
    private function encrypt(string $payload): string
    {
        return bin2hex($this->credentials->cipher()->encrypt(
            (string) mb_convert_encoding($payload, 'ISO-8859-1', 'UTF-8'),
        ));
    }
}
