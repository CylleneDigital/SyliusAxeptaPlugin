<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Payum\Action;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\NotifyAction;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class NotifyActionTest extends TestCase
{
    private const HMAC_KEY = 's3cr3t-hmac-key-for-axepta-tests-0123456789';

    /**
     * The model must be constrained by `supports()`, not only by the assertion in `execute()`:
     * `zend.assertions` is `-1` in production, the assertion disappears there, and an unexpected
     * model would produce a `TypeError` - hence a 500 on the notification URL, which the bank
     * punishes with eight retries over ~21 h.
     */
    public function testDoesNotClaimANotificationWhoseModelIsNotAPayment(): void
    {
        self::assertFalse($this->action()->supports(new Notify(new \ArrayObject(['foo' => 'bar']))));
    }

    public function testClaimsANotificationCarryingAPayment(): void
    {
        $notify = new Notify(new Payment());
        $notify->setModel(new \ArrayObject([]));

        self::assertTrue($this->action()->supports($notify));
    }

    /**
     * The received `TransID` must be the one *this* payment emitted.
     *
     * Nothing requires it today - the notification URL is a random token travelling inside the
     * encrypted payload, so nobody knows another payment's. That is the **only** barrier. The day
     * this path moved to a fixed URL, like the other adapter, a genuine message captured on a €1
     * order would be good for a €5,000 one: the response signature covers neither the amount nor
     * the currency.
     */
    public function testANotificationBearingAnotherTransactionIdIsIgnored(): void
    {
        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->expects(self::never())->method('apply');

        $payment = new Payment();
        $payment->setState(PaymentInterface::STATE_NEW);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/notify', 'POST', $this->authenticNotification()));

        $notify = new Notify($payment);
        $notify->setModel(new \ArrayObject(['transactionId' => 'sylius-1-emitted-by-this-payment']));

        (new NotifyAction($requestStack, $stateMachine, new NotificationVerifier(), new NullLogger()))
            ->execute($notify);

        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());
    }

    /**
     * A genuine notification, but issued for another payment.
     *
     * @return array<string, string>
     */
    private function authenticNotification(): array
    {
        $credentials = new AxeptaCredentials('BNP_TEST_MERCHANT', self::HMAC_KEY, 'aB3dEf9hJk2mNp5q');

        $mac = strtoupper($credentials->signer()->sign([
            'payid-quelconque',
            'sylius-999-another-payment',
            'BNP_TEST_MERCHANT',
            'OK',
            '00000000',
        ]));

        $payload = implode('&', [
            'PayID=payid-quelconque',
            'TransID=sylius-999-another-payment',
            'MerchantID=BNP_TEST_MERCHANT',
            'Status=OK',
            'Code=00000000',
            'MAC=' . $mac,
        ]);

        return ['Data' => bin2hex($credentials->cipher()->encrypt($payload))];
    }

    private function action(): NotifyAction
    {
        return new NotifyAction(
            new RequestStack(),
            $this->createMock(StateMachineInterface::class),
            new NotificationVerifier(),
            new NullLogger(),
        );
    }
}
