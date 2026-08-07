<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use CylleneDigital\SyliusAxeptaPlugin\Payum\AxeptaGatewayFactory;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Formatter\StringInflector;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Sets up an Axepta payment method, with obviously fake keys.
 */
final readonly class AxeptaContext implements Context
{
    /** Test keys: never a real secret in a scenario. */
    private const MERCHANT_ID = 'BNP_TEST_MERCHANT';

    private const HMAC_KEY = 's3cr3t-hmac-key-for-axepta-tests';

    private const BLOWFISH_KEY = 'aB3dEf9hJk2mNp5q';

    /**
     * @param FactoryInterface<PaymentMethodInterface> $paymentMethodFactory
     * @param FactoryInterface<GatewayConfigInterface> $gatewayConfigFactory
     * @param RepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        private SharedStorageInterface $sharedStorage,
        private FactoryInterface $paymentMethodFactory,
        private FactoryInterface $gatewayConfigFactory,
        private RepositoryInterface $paymentMethodRepository,
    ) {
    }

    /**
     * @Given the store has a payment method :name with a code :code and Axepta gateway
     * @Given /^the store has a payment method "([^"]+)" with a code "([^"]+)" and Axepta gateway in (test mode)$/
     */
    public function theStoreHasAnAxeptaPaymentMethod(string $name, string $code, ?string $testMode = null): void
    {
        $this->createPaymentMethod($name, $code, null !== $testMode, usePayum: true);
    }

    /**
     * @Given the store has a payment method :name with a code :code and Axepta gateway on the PaymentRequest path
     */
    public function theStoreHasAnAxeptaPaymentMethodOnThePaymentRequestPath(string $name, string $code): void
    {
        $this->createPaymentMethod($name, $code, false, usePayum: false);
    }

    private function createPaymentMethod(string $name, string $code, bool $testMode, bool $usePayum): void
    {
        $gatewayConfig = $this->gatewayConfigFactory->createNew();
        $gatewayConfig->setFactoryName(AxeptaGatewayFactory::NAME);
        $gatewayConfig->setGatewayName(StringInflector::nameToLowercaseCode($name));
        $gatewayConfig->setConfig([
            'merchant_id' => self::MERCHANT_ID,
            'hmac_key' => self::HMAC_KEY,
            'blowfish_key' => self::BLOWFISH_KEY,
            'test_mode' => $testMode,
        ]);

        $paymentMethod = $this->paymentMethodFactory->createNew();
        $paymentMethod->setCode($code);
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);

        // Depending on the setup context in use, the channel is stored in the singular or in the
        // plural - and sometimes not at all.
        foreach ($this->channels() as $channel) {
            $paymentMethod->addChannel($channel);
        }

        $translation = $paymentMethod->getTranslation();
        $translation->setName($name);

        $this->paymentMethodRepository->add($paymentMethod);
        $this->sharedStorage->set('payment_method', $paymentMethod);
    }

    /**
     * @return iterable<ChannelInterface>
     */
    private function channels(): iterable
    {
        if ($this->sharedStorage->has('channels')) {
            /** @var iterable<ChannelInterface> $channels */
            $channels = $this->sharedStorage->get('channels');

            return $channels;
        }

        if ($this->sharedStorage->has('channel')) {
            $channel = $this->sharedStorage->get('channel');
            \assert($channel instanceof ChannelInterface);

            return [$channel];
        }

        return [];
    }
}
