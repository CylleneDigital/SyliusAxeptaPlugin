<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Controller;

use CylleneDigital\SyliusAxeptaPlugin\Provider\ReturnUrlProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where the customer lands on the way back from the payment page.
 *
 * This route exists because none of the framework's own suits an external caller:
 *
 * - the bank comes back **sometimes over GET, sometimes over POST** depending on the 3-D Secure
 *   journey, whereas the thank-you page and the order page only accept GET;
 * - Sylius' after-payment route does accept both, but its Payum token is **single-use**: a second
 *   pass - a retry from the bank, a refresh, a back button - strands the customer on an error when
 *   they have just paid.
 *
 * It is therefore **idempotent**: replaying it always gives the same result. It decides nothing and
 * changes nothing - the notification is what counts. It reads the current state and sends the
 * customer to the right place.
 *
 * The payment identifier is an integer, paired with a signature: the order token, for its part,
 * cannot travel in this URL. See {@see ReturnUrlProvider}.
 *
 * @internal
 */
final readonly class ReturnFromPaymentPageAction
{
    /** @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository */
    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private ReturnUrlProvider $returnUrlProvider,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(string $paymentId, string $signature): RedirectResponse
    {
        if (!$this->returnUrlProvider->isSignatureValid($paymentId, $signature)) {
            throw new NotFoundHttpException('Invalid return signature.');
        }

        $payment = $this->paymentRepository->find($paymentId);

        if (!$payment instanceof PaymentInterface) {
            throw new NotFoundHttpException(\sprintf('Payment "%s" not found.', $paymentId));
        }

        $order = $payment->getOrder();
        \assert($order instanceof OrderInterface);

        // The locale travels with the order, not with the URL: this route sits outside the
        // shop's language prefix, and without this a French-speaking customer would come back to
        // the English shop after paying.
        $locale = $order->getLocaleCode();

        if ($this->isSettled($order)) {
            return new RedirectResponse(
                $this->urlGenerator->generate('sylius_shop_order_thank_you', ['_locale' => $locale]),
            );
        }

        // Payment refused, abandoned, or notification not in yet: the order page allows a retry,
        // which Sylius makes possible by creating a new payment.
        return new RedirectResponse($this->urlGenerator->generate(
            'sylius_shop_order_show',
            ['tokenValue' => $order->getTokenValue(), '_locale' => $locale],
        ));
    }

    private function isSettled(OrderInterface $order): bool
    {
        $settled = $order->getLastPayment(PaymentInterface::STATE_COMPLETED)
            ?? $order->getLastPayment(PaymentInterface::STATE_AUTHORIZED);

        return null !== $settled;
    }
}
