<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Payum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;

/**
 * Converts the Sylius payment into gateway details.
 *
 * The result is deliberately empty: amount, currency and reference are read straight off the
 * payment and its order by {@see CaptureAction}, the only place where they matter. The details only
 * serve to keep the assigned `TransID`, which the capture writes there.
 *
 * @internal
 */
final class ConvertPaymentAction implements ActionInterface
{
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        \assert($request instanceof Convert);

        $request->setResult([]);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            'array' === $request->getTo();
    }
}
