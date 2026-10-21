<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Payum\Action;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PrefixedTransactionIdGenerator;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\NotifyAction;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class NotifyActionTest extends TestCase
{
    private const HMAC_KEY = 's3cr3t-hmac-key-for-axepta-tests-0123456789';

    private const EARLIER_ATTEMPT = 'sylius-1-0123456789abcdef';

    private const LATEST_ATTEMPT = 'sylius-1-fedcba9876543210';

    /**
     * The model must be constrained by `supports()`, not only by the assertion in `execute()`:
     * `zend.assertions` is `-1` in production, the assertion disappears there, and an unexpected
     * model would produce a `TypeError` - hence a 500 on the notification URL, which the bank
     * punishes with eight retries over ~21 h.
     */
    public function testDoesNotClaimANotificationWhoseModelIsNotAPayment(): void
    {
        self::assertFalse($this->action(new RequestStack(), $this->createMock(StateMachineInterface::class))->supports(new Notify(new \ArrayObject(['foo' => 'bar']))));
    }

    public function testClaimsANotificationCarryingAPayment(): void
    {
        $notify = new Notify(new Payment());
        $notify->setModel(new \ArrayObject([]));

        self::assertTrue($this->action(new RequestStack(), $this->createMock(StateMachineInterface::class))->supports($notify));
    }

    /**
     * The received `TransID` must designate *this* payment.
     *
     * Nothing requires it today - the notification URL is a random token travelling inside the
     * encrypted payload, so nobody knows another payment's. That is the **only** barrier. The day
     * this path moved to a fixed URL, like the other adapter, a genuine message captured on a €1
     * order would be good for a €5,000 one: the response signature covers neither the amount nor
     * the currency.
     */
    public function testANotificationDesignatingAnotherPaymentIsIgnored(): void
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->expects(self::never())->method('apply');

        $payment = $this->payment(1);

        $this->action($this->notifiedWith('sylius-999-0123456789abcdef'), $stateMachine)
            ->execute($this->notify($payment, self::LATEST_ATTEMPT));

        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function attempts(): iterable
    {
        // Sylius resets the details of a payment still `new` on every capture: reopening the
        // payment page emits a new `TransID`, while the bank page of the previous attempt can still
        // be paid. Its payment must settle the order - otherwise the bank takes the money, receives
        // the success response and never retries, and the order stays unpaid.
        yield 'earlier attempt paid' => [self::EARLIER_ATTEMPT, 'OK', PaymentTransitions::TRANSITION_COMPLETE];
        yield 'latest attempt paid' => [self::LATEST_ATTEMPT, 'OK', PaymentTransitions::TRANSITION_COMPLETE];
        // A refusal of an earlier attempt must not fail the payment: the customer may have paid on
        // the latest one, whose notification could then no longer complete it.
        yield 'earlier attempt refused' => [self::EARLIER_ATTEMPT, 'FAILED', null];
        yield 'latest attempt refused' => [self::LATEST_ATTEMPT, 'FAILED', PaymentTransitions::TRANSITION_FAIL];
    }

    #[DataProvider('attempts')]
    public function testANotificationOfThePaymentSettlesItAccordingToItsAttempt(string $transactionId, string $status, ?string $expectedTransition): void
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->willReturn(true);

        if (null === $expectedTransition) {
            $stateMachine->expects(self::never())->method('apply');
        } else {
            $stateMachine->expects(self::once())
                ->method('apply')
                ->with(self::anything(), PaymentTransitions::GRAPH, $expectedTransition);
        }

        $this->action($this->notifiedWith($transactionId, $status), $stateMachine)
            ->execute($this->notify($this->payment(1), self::LATEST_ATTEMPT));
    }

    /**
     * The inverse of the generator cannot give back a UUID - the dashes are stripped from the
     * `TransID` - nor anything a substituted generator does not choose to: the latest attempt is
     * still recognised on its stored `TransID`.
     */
    public function testTheLatestAttemptOfAPaymentTheGeneratorCannotResolveIsApplied(): void
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->willReturn(true);
        $stateMachine->expects(self::once())
            ->method('apply')
            ->with(self::anything(), PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);

        $latestAttempt = 'sylius-550e8400e29b41d4a716446655440000-fedcba9876543210';

        $this->action($this->notifiedWith($latestAttempt), $stateMachine)
            ->execute($this->notify($this->payment('550e8400-e29b-41d4-a716-446655440000'), $latestAttempt));
    }

    public function testANotificationWhoseTransactionIdDesignatesNoPaymentIsIgnored(): void
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->expects(self::never())->method('apply');

        $this->action($this->notifiedWith('not-issued-by-this-plugin'), $stateMachine)
            ->execute($this->notify($this->payment(1), self::LATEST_ATTEMPT));
    }

    private function action(RequestStack $requestStack, StateMachineInterface $stateMachine): NotifyAction
    {
        $action = new NotifyAction(
            $requestStack,
            $stateMachine,
            new NotificationVerifier(),
            new PrefixedTransactionIdGenerator(),
            new NullLogger(),
        );
        $action->setApi($this->credentials());

        return $action;
    }

    /** A genuine notification, as the bank posts it. */
    private function notifiedWith(string $transactionId, string $status = 'OK'): RequestStack
    {
        $credentials = $this->credentials();
        $code = 'OK' === $status ? '00000000' : '22720040';

        $mac = strtoupper($credentials->signer()->sign([
            'payid-quelconque',
            $transactionId,
            'BNP_TEST_MERCHANT',
            $status,
            $code,
        ]));

        $payload = implode('&', [
            'PayID=payid-quelconque',
            'TransID=' . $transactionId,
            'MerchantID=BNP_TEST_MERCHANT',
            'Status=' . $status,
            'Code=' . $code,
            'MAC=' . $mac,
        ]);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/notify', 'POST', ['Data' => bin2hex($credentials->cipher()->encrypt($payload))]));

        return $requestStack;
    }

    private function notify(PaymentInterface $payment, string $storedTransactionId): Notify
    {
        $notify = new Notify($payment);
        $notify->setModel(new \ArrayObject(['transactionId' => $storedTransactionId]));

        return $notify;
    }

    private function payment(int|string $id): Payment
    {
        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_NEW);
        (new \ReflectionProperty(Payment::class, 'id'))->setValue($payment, $id);

        return $payment;
    }

    private function credentials(): AxeptaCredentials
    {
        return new AxeptaCredentials('BNP_TEST_MERCHANT', self::HMAC_KEY, 'aB3dEf9hJk2mNp5q');
    }
}
