<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Payum\Action;

use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\ResolveNextRouteAction;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\PayumBundle\Request\ResolveNextRoute;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * This is the action deciding where the customer lands on the way back from the bank. It stayed
 * unreachable for a while: the return URLs used to target the final page directly.
 */
final class ResolveNextRouteActionTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function settledStates(): iterable
    {
        yield 'completed' => [PaymentInterface::STATE_COMPLETED];
        yield 'authorised' => [PaymentInterface::STATE_AUTHORIZED];
    }

    #[DataProvider('settledStates')]
    public function testASettledPaymentGoesToTheThankYouPage(string $state): void
    {
        $request = new ResolveNextRoute($this->payment($state));

        (new ResolveNextRouteAction())->execute($request);

        self::assertSame('sylius_shop_order_thank_you', $request->getRouteName());
        self::assertSame([], $request->getRouteParameters());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsettledStates(): iterable
    {
        yield 'failed' => [PaymentInterface::STATE_FAILED];
        yield 'cancelled' => [PaymentInterface::STATE_CANCELLED];
        yield 'pending' => [PaymentInterface::STATE_NEW];
    }

    /** The customer must be able to retry from their order, not be thanked for nothing. */
    #[DataProvider('unsettledStates')]
    public function testAnUnsettledPaymentGoesBackToTheOrder(string $state): void
    {
        $request = new ResolveNextRoute($this->payment($state));

        (new ResolveNextRouteAction())->execute($request);

        self::assertSame('sylius_shop_order_show', $request->getRouteName());
        self::assertSame(['tokenValue' => 'ORDER-TOKEN'], $request->getRouteParameters());
    }

    public function testDoesNotClaimARequestWhoseModelIsStillAToken(): void
    {
        $token = $this->createMock(TokenInterface::class);

        self::assertFalse((new ResolveNextRouteAction())->supports(new ResolveNextRoute($token)));
    }

    private function payment(string $state): PaymentInterface
    {
        $order = new Order();
        $order->setTokenValue('ORDER-TOKEN');

        $payment = new Payment();
        $payment->setOrder($order);
        $payment->setState($state);

        return $payment;
    }
}
