<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Sylius\Bundle\PaymentBundle\Attribute\AsNotifyPaymentProvider;
use Sylius\Bundle\PaymentBundle\Provider\GatewayFactoryNameProviderInterface;
use Sylius\Bundle\PaymentBundle\Provider\NotifyPaymentProviderInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Finds the payment a notification refers to, when it arrives on the payment method's fixed URL.
 *
 * The bank returns no usable order identifier: the only reliable link is the `TransID`, which is
 * part of the response signature and is therefore always present.
 *
 * It is the **generator** that gives the payment back, through the inverse of the operation that
 * emitted it
 * ({@see \CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface::resolvePaymentIdentifier()}).
 * A primary key lookup, then, not a scan.
 *
 * > The previous version compared the received `TransID` against those of the 500 most recently
 * > updated captures, so as not to depend on the identifier format. The cure was worse: opening
 * > 500 payments and abandoning them was enough to **push a real customer's capture** out of the
 * > window. Their notification then went to a 404, the bank retried eight times over ~21 h, and the
 * > order stayed unpaid - while the money had been taken. Making the inverse a clause of the
 * > contract costs less than guessing.
 *
 * @experimental
 *
 * @internal
 */
#[AsNotifyPaymentProvider]
final readonly class AxeptaNotifyPaymentProvider implements NotifyPaymentProviderInterface
{
    /**
     * @param PaymentRepositoryInterface<\Sylius\Component\Core\Model\PaymentInterface> $paymentRepository
     */
    public function __construct(
        private PaymentRepositoryInterface $paymentRepository,
        private CredentialsProvider $credentialsProvider,
        private NotificationVerifier $notificationVerifier,
        private TransactionIdGeneratorInterface $transactionIdGenerator,
        private GatewayFactoryNameProviderInterface $gatewayFactoryNameProvider,
        private string $gatewayFactoryName,
    ) {
    }

    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        if ($this->gatewayFactoryName !== $this->gatewayFactoryNameProvider->provide($paymentMethod)) {
            return false;
        }

        // Accepted limitation of v1: cards notify over POST. Alternative payment means notify
        // over GET and are not in scope yet.
        return $request->isMethod('POST') && $request->request->has('Data');
    }

    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        // Symfony 7 annotates `all()` as `array<string, mixed>`; 6.4 returns a bare `array`,
        // which static analysis refuses to pass to the verifier.
        /** @var array<string, mixed> $requestParameters */
        $requestParameters = $request->request->all();

        $notification = $this->notificationVerifier->verify(
            $this->credentialsProvider->fromPaymentMethod($paymentMethod),
            $requestParameters,
        );

        // The signature is checked again by the handler; here it acts as an entry guard, so that
        // pending payments are not looked up on the strength of a forged message.
        $transactionId = $notification->macValid ? $notification->transactionId() : null;

        if (null === $transactionId || '' === $transactionId) {
            throw new NotFoundHttpException('Axepta notification without an identifiable transaction.');
        }

        $paymentId = $this->transactionIdGenerator->resolvePaymentIdentifier($transactionId);

        if (null === $paymentId) {
            throw new NotFoundHttpException(\sprintf(
                'Transaction "%s" designates no payment: the identifier generator does not '
                . 'recognise it.',
                $transactionId,
            ));
        }

        $payment = $this->paymentRepository->find($paymentId);

        // The match must hold for *this* payment method: without this check, a genuine
        // notification addressed to one gateway would grant access to another's payments, possibly
        // configured with different keys.
        if (!$payment instanceof PaymentInterface || $payment->getMethod()?->getId() !== $paymentMethod->getId()) {
            throw new NotFoundHttpException(\sprintf(
                'No payment of this method matches transaction "%s".',
                $transactionId,
            ));
        }

        return $payment;
    }
}
