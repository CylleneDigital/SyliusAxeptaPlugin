<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Sylius\Bundle\PayumBundle\Request\ResolveNextRoute;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Decides where to send the customer when Payum takes over after a capture.
 *
 * **This is not the return path from the bank** - that one goes through the plugin's own route,
 * {@see \CylleneDigital\SyliusAxeptaPlugin\Controller\ReturnFromPaymentPageAction}. This action
 * covers the case where the customer comes back to the capture URL of an **already completed**
 * payment: a refresh, a back button, a link left open in a tab. `CaptureAction` then returns
 * without doing anything, the Payum controller invalidates the token and redirects to the
 * after-payment route, which executes this action.
 *
 * The behaviour is Sylius' own - thank-you page if the payment settled, order page otherwise -
 * registered on the `axepta` factory only, so that it never alters the other gateways.
 *
 * @internal
 */
final class ResolveNextRouteAction implements ActionInterface
{
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        \assert($request instanceof ResolveNextRoute);

        $payment = $request->getFirstModel();
        \assert($payment instanceof PaymentInterface);

        if (\in_array($payment->getState(), [PaymentInterface::STATE_COMPLETED, PaymentInterface::STATE_AUTHORIZED], true)) {
            $request->setRouteName('sylius_shop_order_thank_you');

            return;
        }

        $order = $payment->getOrder();
        \assert($order instanceof OrderInterface);

        $request->setRouteName('sylius_shop_order_show');
        $request->setRouteParameters(['tokenValue' => $order->getTokenValue()]);
    }

    public function supports($request): bool
    {
        return $request instanceof ResolveNextRoute &&
            $request->getFirstModel() instanceof PaymentInterface;
    }
}
