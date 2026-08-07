<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Integration;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\InvalidCredentialsException;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CredentialsProviderTest extends KernelTestCase
{
    /**
     * Migration invariant: payment methods already in the database only carry the three original
     * keys. Their configuration must stay usable as is, without a data migration.
     */
    public function testBuildsCredentialsFromALegacyConfiguration(): void
    {
        $credentials = $this->provider()->fromPaymentMethod($this->paymentMethod([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 's3cr3t-hmac-key-for-axepta-tests',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]));

        self::assertSame('BNP_TEST_MERCHANT', $credentials->merchantId);
        self::assertSame(AxeptaCredentials::DEFAULT_PAYMENT_PAGE_URL, $credentials->paymentPageUrl);
    }

    public function testBuildsWorkingCryptographicPrimitives(): void
    {
        $credentials = $this->provider()->fromPaymentMethod($this->paymentMethod([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 'mySecret',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]));

        // Official BNP vector: proves the HMAC key reaches the signer intact.
        self::assertSame(
            strtolower('F1DE7608013C1E3FD3CC9964A049E26703137C0A6F29448545C700B4695EABE5'),
            $credentials->signer()->sign(
                ['7bbb448155234d8cbee323778952ce28', 'TID-12033175321270170232', 'YourMerchantID', 'AUTHORIZED', '00000000'],
            ),
        );

        self::assertSame('ciphered', $credentials->cipher()->decrypt($credentials->cipher()->encrypt('ciphered')));
    }

    /** The new keys are optional: their absence must break nothing. */
    public function testToleratesMissingOptionalKeys(): void
    {
        $credentials = $this->provider()->fromPaymentMethod($this->paymentMethod([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 'hmac',
            'blowfish_key' => 'blowfish',
            'test_mode' => true,
            'language' => 'fr',
        ]));

        self::assertSame('BNP_TEST_MERCHANT', $credentials->merchantId);
    }

    public function testRejectsAnEmptyKey(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->provider()->fromPaymentMethod($this->paymentMethod([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => '',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]));
    }

    public function testRejectsAPaymentMethodWithoutGatewayConfiguration(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->provider()->fromPaymentMethod(new PaymentMethod());
    }

    private function provider(): CredentialsProvider
    {
        self::bootKernel();

        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.provider.credentials');
        \assert($provider instanceof CredentialsProvider);

        return $provider;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentMethod(array $config): PaymentMethod
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName('axepta');
        $gatewayConfig->setGatewayName('axepta');
        $gatewayConfig->setConfig($config);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('axepta');
        $paymentMethod->setGatewayConfig($gatewayConfig);

        return $paymentMethod;
    }
}
