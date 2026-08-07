<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Mirrors the state of the Sylius payment into the Payum status.
 *
 * The truth comes from the notification, which applies the transition; this action merely reports
 * the current state to the checkout. It never queries the bank.
 *
 * @internal
 */
final class StatusAction implements ActionInterface
{
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        \assert($request instanceof GetStatusInterface);

        $payment = $request->getModel();
        \assert($payment instanceof PaymentInterface);

        match ($payment->getState()) {
            PaymentInterface::STATE_COMPLETED => $request->markCaptured(),
            PaymentInterface::STATE_AUTHORIZED => $request->markAuthorized(),
            PaymentInterface::STATE_CANCELLED => $request->markCanceled(),
            PaymentInterface::STATE_FAILED => $request->markFailed(),
            PaymentInterface::STATE_REFUNDED => $request->markRefunded(),
            PaymentInterface::STATE_PROCESSING => $request->markPending(),
            PaymentInterface::STATE_NEW, PaymentInterface::STATE_CART => $request->markNew(),
            default => $request->markUnknown(),
        };
    }

    /**
     * The model must be constrained, not only the request type.
     *
     * Sylius asks for the status by passing the **token**, not the payment: resolving it is Payum's
     * job, through `ExecuteSameRequestWithModelDetailsAction` and then the storage extension. An
     * action accepting any `GetStatusInterface` short-circuits that resolution, and the model is
     * never replaced - `Generic::setFirstModel()` precisely ignoring tokens and identities.
     *
     * The symptom is delayed: the `ResolveNextRoute` request that follows is then built with a null
     * model, and Payum declares it unsupported after the payment, when the customer comes back from
     * the bank.
     */
    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface &&
            $request->getModel() instanceof PaymentInterface;
    }
}
