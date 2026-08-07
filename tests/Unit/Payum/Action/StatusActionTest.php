<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Payum\Action;

use CylleneDigital\SyliusAxeptaPlugin\Payum\Action\StatusAction;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;

final class StatusActionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function states(): iterable
    {
        yield 'completed' => [PaymentInterface::STATE_COMPLETED, GetHumanStatus::STATUS_CAPTURED];
        yield 'authorised' => [PaymentInterface::STATE_AUTHORIZED, GetHumanStatus::STATUS_AUTHORIZED];
        yield 'cancelled' => [PaymentInterface::STATE_CANCELLED, GetHumanStatus::STATUS_CANCELED];
        yield 'failed' => [PaymentInterface::STATE_FAILED, GetHumanStatus::STATUS_FAILED];
        yield 'refunded' => [PaymentInterface::STATE_REFUNDED, GetHumanStatus::STATUS_REFUNDED];
        yield 'en cours' => [PaymentInterface::STATE_PROCESSING, GetHumanStatus::STATUS_PENDING];
        yield 'new' => [PaymentInterface::STATE_NEW, GetHumanStatus::STATUS_NEW];
    }

    #[DataProvider('states')]
    public function testReportsThePaymentState(string $state, string $expected): void
    {
        $payment = new Payment();
        $payment->setState($state);

        $request = new GetHumanStatus($payment);
        (new StatusAction())->execute($request);

        self::assertSame($expected, $request->getValue());
    }

    /**
     * Sylius asks for the status by passing the **token**, not the payment. Accepting this
     * request would short-circuit Payum's model resolution: the `ResolveNextRoute` request that
     * follows would be built with a null model, and the customer would hit an error on the way back
     * from the bank - after paying.
     */
    public function testDoesNotClaimARequestWhoseModelIsStillAToken(): void
    {
        $token = $this->createMock(TokenInterface::class);

        self::assertFalse((new StatusAction())->supports(new GetHumanStatus($token)));
    }

    public function testClaimsARequestCarryingAPayment(): void
    {
        self::assertTrue((new StatusAction())->supports(new GetHumanStatus(new Payment())));
    }
}
