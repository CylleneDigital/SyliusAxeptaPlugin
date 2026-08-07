<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Provider;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcbFactoryInterface;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\InvalidCredentialsException;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;

/**
 * Derives the Axepta credentials from the gateway configuration entered in the back office.
 *
 * The single point both payment paths go through: this is where the container's cipher factory is
 * injected, hence where substituting a native implementation takes effect.
 */
final readonly class CredentialsProvider
{
    public function __construct(
        private BlowfishEcbFactoryInterface $cipherFactory,
        private string $paymentPageUrl,
    ) {
    }

    public function fromPaymentMethod(PaymentMethodInterface $paymentMethod): AxeptaCredentials
    {
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        if (!$gatewayConfig instanceof GatewayConfigInterface) {
            throw new InvalidCredentialsException(\sprintf(
                'Payment method "%s" has no gateway configuration.',
                (string) $paymentMethod->getCode(),
            ));
        }

        return $this->fromArray($gatewayConfig->getConfig());
    }

    /**
     * @param array<string, mixed> $config
     */
    public function fromArray(array $config): AxeptaCredentials
    {
        return new AxeptaCredentials(
            $this->string($config, 'merchant_id'),
            $this->string($config, 'hmac_key'),
            $this->string($config, 'blowfish_key'),
            // Payment methods created before this setting was introduced do not carry the key:
            // its absence must stay silent, otherwise migrating would require a data migration.
            $this->string($config, 'payment_page_url') ?: $this->paymentPageUrl,
            $this->cipherFactory,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function string(array $config, string $name): string
    {
        $value = $config[$name] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
