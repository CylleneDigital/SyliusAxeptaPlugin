<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Utils;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets up in the database the bare minimum for an Axepta payment: a channel, an order and a
 * payment tied to a configured payment method.
 *
 * The key values are obviously fake - never a real secret in a test.
 */
final class PaymentScenario
{
    public const MERCHANT_ID = 'BNP_TEST_MERCHANT';

    public const HMAC_KEY = 's3cr3t-hmac-key-for-axepta-tests';

    public const BLOWFISH_KEY = 'aB3dEf9hJk2mNp5q';

    /** Unique per call **and** per process: the tests can run without a pristine database. */
    private static int $sequence = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContainerInterface $container,
    ) {
    }

    public function createPaymentMethod(bool $usePayum = true, bool $testMode = false): PaymentMethodInterface
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName('axepta');
        $gatewayConfig->setGatewayName('axepta_' . $this->nextSequence());
        $gatewayConfig->setUsePayum($usePayum);
        $gatewayConfig->setConfig([
            'merchant_id' => self::MERCHANT_ID,
            'hmac_key' => self::HMAC_KEY,
            'blowfish_key' => self::BLOWFISH_KEY,
            'test_mode' => $testMode,
        ]);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setFallbackLocale('en_US');
        $paymentMethod->setCode('axepta_' . $this->nextSequence());
        $paymentMethod->setName('Axepta');
        $paymentMethod->setGatewayConfig($gatewayConfig);
        $paymentMethod->setEnabled(true);

        $this->entityManager->persist($gatewayConfig);
        $this->entityManager->persist($paymentMethod);

        return $paymentMethod;
    }

    public function createPayment(PaymentMethodInterface $paymentMethod, int $amountInCents = 4200): PaymentInterface
    {
        $order = $this->createOrder();

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setMethod($paymentMethod);
        $payment->setCurrencyCode('EUR');
        $payment->setAmount($amountInCents);
        $payment->setState(PaymentInterface::STATE_NEW);

        $order->addPayment($payment);

        $this->entityManager->persist($payment);

        return $payment;
    }

    /**
     * A payment request as the capture leaves it: `processing`, carrying the emitted `TransID`.
     * This is the record the notification will find again.
     */
    public function createProcessingCapture(PaymentInterface $payment, string $transactionId): PaymentRequestInterface
    {
        $paymentMethod = $payment->getMethod();
        \assert($paymentMethod instanceof PaymentMethodInterface);

        $paymentRequest = new PaymentRequest($payment, $paymentMethod);
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);
        $paymentRequest->setState(PaymentRequestInterface::STATE_PROCESSING);
        $paymentRequest->setResponseData([
            'url' => AxeptaCredentials::DEFAULT_PAYMENT_PAGE_URL,
            'fields' => ['TransID' => $transactionId, 'MerchantID' => self::MERCHANT_ID],
        ]);

        $this->entityManager->persist($paymentRequest);

        return $paymentRequest;
    }

    public function credentials(PaymentMethodInterface $paymentMethod): AxeptaCredentials
    {
        $provider = $this->container->get('cyllene_digital_sylius_axepta.provider.credentials');
        \assert($provider instanceof CredentialsProvider);

        return $provider->fromPaymentMethod($paymentMethod);
    }

    public function forger(PaymentMethodInterface $paymentMethod): AxeptaNotificationForger
    {
        return new AxeptaNotificationForger($this->credentials($paymentMethod), self::HMAC_KEY);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * Reads a payment's state back from the database, bypassing the test's own instance.
     *
     * The HTTP request works on its own entity manager: the object the test holds stays on the
     * pre-notification value, and a plain `refresh()` is not enough to get it out of that. Only the
     * database counts here.
     */
    public function reloadPaymentState(PaymentInterface $payment): string
    {
        $state = $this->entityManager->getConnection()->fetchOne(
            'SELECT state FROM sylius_payment WHERE id = :id',
            ['id' => $payment->getId()],
        );

        return \is_string($state) ? $state : '';
    }

    private function createOrder(): OrderInterface
    {
        $sequence = $this->nextSequence();

        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if (!$currency instanceof Currency) {
            $currency = new Currency();
            $currency->setCode('EUR');
            $this->entityManager->persist($currency);
        }

        $locale = $this->entityManager->getRepository(Locale::class)->findOneBy(['code' => 'en_US']);
        if (!$locale instanceof Locale) {
            $locale = new Locale();
            $locale->setCode('en_US');
            $this->entityManager->persist($locale);
        }

        $channel = new Channel();
        $channel->setCode('axepta_channel_' . $sequence);
        $channel->setName('Axepta test channel');
        $channel->setHostname('axepta.test');
        $channel->setTaxCalculationStrategy('order_items_based');
        $channel->setBaseCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addCurrency($currency);
        $channel->addLocale($locale);
        $channel->setEnabled(true);

        $order = new Order();
        $order->setChannel($channel);
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('en_US');
        $order->setNumber(substr(preg_replace('/[^0-9]/', '', $sequence) ?? '0', -9));
        $order->setTokenValue('token_' . $sequence);
        $order->setState(OrderInterface::STATE_NEW);

        $this->entityManager->persist($channel);
        $this->entityManager->persist($order);

        return $order;
    }

    private function nextSequence(): string
    {
        return uniqid((string) ++self::$sequence, true);
    }
}
