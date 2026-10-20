<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\InvalidCredentialsException;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
        private string $gatewayFactoryName,
    ) {
    }

    /**
     * Every request on an Axepta payment method is claimed, usable or not.
     *
     * Leaving one unclaimed would hand it to Sylius' composite provider, which throws a
     * `LogicException` when no provider supports the request: a 500 instead of the 404 below, and
     * no trace in the Axepta log.
     */
    public function supports(Request $request, PaymentMethodInterface $paymentMethod): bool
    {
        return $this->gatewayFactoryName === $this->gatewayFactoryNameProvider->provide($paymentMethod);
    }

    /**
     * Every refusal of a message below answers a 404, which makes the bank retry eight times over
     * ~21 h 36.
     *
     * That is wanted: Sylius' contract leaves no other way out than a payment or an exception, and
     * the retries recover a genuine notification whose cause is fixed within that window -
     * typically an HMAC key rotated on one side only while payments were in flight. A forged or
     * replayed message triggers no retry: the bank only retries what it sent itself.
     */
    public function getPayment(Request $request, PaymentMethodInterface $paymentMethod): PaymentInterface
    {
        // Accepted limitation of v1: cards notify over POST. Alternative payment means notify
        // over GET and are not in scope yet - one that does reach this URL may have been paid.
        if ($request->isMethod('GET') && $request->query->has('Data')) {
            $this->logger->error('Axepta notification over GET, as alternative payment means send it, is not supported: rejected. The payment page offered more than cards, check `payment_page_url`.', [
                'payment_method' => $paymentMethod->getCode(),
                'client_ip' => $request->getClientIp(),
            ]);

            throw new NotFoundHttpException('Axepta notification over GET is not supported.');
        }

        if (!$request->isMethod('POST') || !$request->request->has('Data')) {
            $this->logger->warning('Axepta notification without an encrypted payload: rejected.', [
                'payment_method' => $paymentMethod->getCode(),
                'http_method' => $request->getMethod(),
                'client_ip' => $request->getClientIp(),
            ]);

            throw new NotFoundHttpException('Axepta notification without an encrypted payload.');
        }

        try {
            $credentials = $this->credentialsProvider->fromPaymentMethod($paymentMethod);
        } catch (InvalidCredentialsException $exception) {
            $this->logger->error('Axepta notification on a payment method whose configuration is incomplete: rejected.', [
                'payment_method' => $paymentMethod->getCode(),
                'exception' => $exception,
            ]);

            throw new NotFoundHttpException('Axepta payment method not configured.', $exception);
        }

        // Symfony 7 annotates `all()` as `array<string, mixed>`; 6.4 returns a bare `array`,
        // which static analysis refuses to pass to the verifier.
        /** @var array<string, mixed> $requestParameters */
        $requestParameters = $request->request->all();

        $notification = $this->notificationVerifier->verify($credentials, $requestParameters);

        // The signature is checked again by the handler; here it acts as an entry guard, so that
        // pending payments are not looked up on the strength of a forged message.
        if (!$notification->macValid) {
            // Anyone can reach this URL: nothing read from the message is trusted, the level stays
            // a warning, and the signature code is a hint, not a diagnosis.
            $this->logger->warning(
                $notification->signalsSignatureMismatch()
                    ? 'Axepta notification rejected on a signature code: check the configured HMAC key, a rotation may have been applied on the bank side and not here.'
                    : 'Axepta notification rejected, no payment looked up.',
                [
                    'payment_method' => $paymentMethod->getCode(),
                    'reason' => $notification->rejection?->value,
                    'code' => $notification->code(),
                    'unauthenticated_transaction_id' => mb_substr((string) $notification->transactionId(), 0, 64),
                    'client_ip' => $request->getClientIp(),
                ],
            );

            throw new NotFoundHttpException('Axepta notification not authenticated.');
        }

        // From here on the message is genuine: only the bank can send it, money may have been
        // taken, and failing to tie it to a payment is an error.
        $transactionId = $notification->transactionId();
        $paymentId = null === $transactionId || '' === $transactionId
            ? null
            : $this->transactionIdGenerator->resolvePaymentIdentifier($transactionId);
        $payment = null === $paymentId ? null : $this->paymentRepository->find($paymentId);

        // The match must hold for *this* payment method: without this check, a genuine
        // notification addressed to one gateway would grant access to another's payments, possibly
        // configured with different keys.
        if (!$payment instanceof PaymentInterface || $payment->getMethod()?->getId() !== $paymentMethod->getId()) {
            $this->logger->error('Authenticated Axepta notification matching no payment of this method: rejected with a 404, the bank retries for ~21 h. Find the payment or settle it by hand.', [
                'payment_method' => $paymentMethod->getCode(),
                'transaction_id' => $transactionId,
                'resolved_payment_id' => $paymentId,
                'status' => $notification->status?->value,
                'code' => $notification->code(),
                'pay_id' => $notification->payId(),
            ]);

            throw new NotFoundHttpException(\sprintf(
                'No payment of this method matches transaction "%s".',
                $transactionId,
            ));
        }

        return $payment;
    }
}
