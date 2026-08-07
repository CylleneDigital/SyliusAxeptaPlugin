<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Utils\AxeptaNotificationForger;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Utils\PaymentScenario;

/**
 * End-to-end notification on the `PaymentRequest` path, real HTTP request included.
 *
 * It is the only point of the plugin exposed to the outside, and the only one where a mistake is
 * expensive: a 404 or a 500 triggers 8 BNP retries spread over ~21 h 36.
 */
final class PaymentRequestNotifyTest extends WebTestCase
{
    private KernelBrowser $client;

    private PaymentScenario $scenario;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        // Deliberately without a wrapping transaction: the bus' `doctrine_transaction` middleware
        // would not commit inside an already open transaction, and the state applied to the payment
        // would never be written. Each scenario creates its own records, uniquely identified - two
        // successive runs do not tread on each other.
        $this->scenario = new PaymentScenario($entityManager, self::getContainer());
    }

    public function testAnAuthenticNotificationCompletesThePayment(): void
    {
        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('authentic');

        $this->notify($paymentMethod, $forger->forge($transactionId));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->stateOf($payment));
    }

    /**
     * A double notification is the nominal case at BNP, not the exception. It must be a no-op
     * **and** free of any 404: that is the whole point of notifying on the payment method URL.
     */
    public function testAReplayedNotificationChangesNothingAndStillAnswersOk(): void
    {
        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('replayed');
        $notification = $forger->forge($transactionId);

        $this->notify($paymentMethod, $notification);
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->stateOf($payment));

        $this->notify($paymentMethod, $notification);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->stateOf($payment));
    }

    /**
     * A double notification is the nominal case at BNP: on a payment notified eight times, the
     * log must tell the settlement from its retries. Without that, nobody in operations can say
     * whether a payment has just gone through or has been replaying for twenty hours.
     */
    public function testAReplayedNotificationIsLoggedAsHavingNoEffect(): void
    {
        // Without this the client reboots the kernel on every request, and the collector queried
        // below is no longer the one that received the records. The service resetter empties it
        // between requests anyway: so we read after each one rather than at the end.
        $this->client->disableReboot();

        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('replaylog');
        $notification = $forger->forge($transactionId);

        $this->notify($paymentMethod, $notification);
        self::assertContains('Axepta notification applied.', $this->axeptaMessages());

        $this->notify($paymentMethod, $notification);
        self::assertContains(
            'Axepta notification with no effect: the transition no longer applies.',
            $this->axeptaMessages(),
        );
    }

    /**
     * The status enum is not exhaustive from the platform's point of view, and it may introduce
     * values without notice. Treating the unknown as a failure would mark as refused a payment that
     * may have been taken - the more destructive of the two possible behaviours.
     */
    public function testAnUnknownStatusLeavesThePaymentUntouched(): void
    {
        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('unknownstatus');

        $this->notify($paymentMethod, $forger->forge($transactionId, 'UNDER_REVIEW'));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_NEW, $this->stateOf($payment));
    }

    public function testARefusedPaymentFails(): void
    {
        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('refused');

        $this->notify($paymentMethod, $forger->forge($transactionId, 'FAILED', '22720040'));

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_FAILED, $this->stateOf($payment));
    }

    /** `AUTHORIZED` counts as accepted, just like `OK`. */
    public function testAnAuthorizedNotificationCompletesThePayment(): void
    {
        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('authorized');

        $this->notify($paymentMethod, $forger->forge($transactionId, 'AUTHORIZED'));

        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->stateOf($payment));
    }

    /**
     * Signature not genuine: no state changed.
     *
     * The notification cannot be tied to any payment, which yields a 404. That does not contradict
     * the "never a 404 on the notification URL" rule: the retries a 404 triggers are emitted by the
     * bank, and the bank does not emit forged messages. An attacker receiving this 404 has
     * triggered nothing.
     */
    public function testATamperedNotificationLeavesThePaymentUntouched(): void
    {
        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('tampered');

        $this->notify($paymentMethod, $forger->forgeWithTamperedStatus($transactionId));

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_NEW, $this->stateOf($payment));
    }

    /** An unreadable `Data` must produce neither an exception nor a 500. */
    public function testAnUnreadablePayloadIsRejectedWithoutError(): void
    {
        [$paymentMethod, $payment] = $this->pendingCapture('unreadable');

        $this->client->request('POST', '/payment-methods/' . $paymentMethod->getCode(), ['Data' => 'pas-de-l-hexa']);

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_NEW, $this->stateOf($payment));
    }

    /**
     * Documented limitation of v1: cards notify over POST. A GET notification, the one used by
     * alternative payment means, must change nothing, but must not break loudly either.
     */
    public function testAGetNotificationHasNoEffect(): void
    {
        [$paymentMethod, $payment, $forger, $transactionId] = $this->pendingCapture('get');

        $this->client->request('GET', '/payment-methods/' . $paymentMethod->getCode(), $forger->forge($transactionId));

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_NEW, $this->stateOf($payment));
    }

    /**
     * An unknown transaction matches no capture in flight.
     *
     * This is the only case where the 404 is *desirable*: if the notification is genuine and the
     * capture is not visible yet, the bank's retries will eventually succeed.
     */
    public function testAnUnknownTransactionIsNotAttachedToAnyPayment(): void
    {
        [$paymentMethod, $payment, $forger] = $this->pendingCapture('known');

        // A genuine notification, but designating a payment that does not exist.
        $this->client->request(
            'POST',
            '/payment-methods/' . $paymentMethod->getCode(),
            $forger->forge('sylius-999999-inconnu'),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame(PaymentInterface::STATE_NEW, $this->stateOf($payment));
    }

    /**
     * The `TransID` must carry the real payment identifier: it is how the notification provider
     * finds it again, by primary key. Composing it by hand in every test would make them wrong at
     * the first change of convention.
     *
     * @return array{PaymentMethodInterface, PaymentInterface, AxeptaNotificationForger, string}
     */
    private function pendingCapture(string $suffix): array
    {
        $paymentMethod = $this->scenario->createPaymentMethod(usePayum: false);
        $payment = $this->scenario->createPayment($paymentMethod);
        $this->scenario->flush();

        $paymentId = $payment->getId();
        \assert(\is_int($paymentId));

        $transactionId = \sprintf('sylius-%d-%s', $paymentId, $suffix);
        $this->scenario->createProcessingCapture($payment, $transactionId);
        $this->scenario->flush();

        return [$paymentMethod, $payment, $this->scenario->forger($paymentMethod), $transactionId];
    }

    /**
     * @param array<string, string> $notification
     */
    private function notify(PaymentMethodInterface $paymentMethod, array $notification): void
    {
        $this->client->request('POST', '/payment-methods/' . $paymentMethod->getCode(), $notification);
    }

    private function stateOf(PaymentInterface $payment): string
    {
        return $this->scenario->reloadPaymentState($payment);
    }

    /**
     * The messages logged on the `axepta` channel by the last request, read off the collector
     * declared in `tests/TestApplication/config/config.yaml`.
     *
     * @return list<string>
     */
    private function axeptaMessages(): array
    {
        $handler = self::getContainer()->get('monolog.handler.axepta_assertions');
        \assert($handler instanceof TestHandler);

        return array_values(
            array_map(static fn (LogRecord $record): string => $record->message, $handler->getRecords()),
        );
    }
}
