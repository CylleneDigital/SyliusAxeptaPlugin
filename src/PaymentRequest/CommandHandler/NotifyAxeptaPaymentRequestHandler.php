<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\NotifyAxeptaPaymentRequest;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\PaymentRequest\Resolver\DoctrineProxyObjectResolverInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Authenticates a notification and advances **two** state machines: the payment request's, and the
 * payment's own. Forgetting the second would leave the order unpaid while the request reads
 * `completed`.
 *
 * Never lets an exception surface: the bus is synchronous, so an exception would come out as a 500
 * on the notification URL and trigger 8 BNP retries spread over ~21 h 36.
 *
 * @experimental
 *
 * @internal
 */
#[AsMessageHandler(bus: 'sylius.payment_request.command_bus')]
final readonly class NotifyAxeptaPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsProvider $credentialsProvider,
        private NotificationVerifier $notificationVerifier,
        private StateMachineInterface $stateMachine,
        private DoctrineProxyObjectResolverInterface $proxyObjectResolver,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotifyAxeptaPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        try {
            $this->handle($paymentRequest);
        } catch (\Throwable $exception) {
            $this->logger->error('Handling an Axepta notification failed.', [
                'payment_request_hash' => (string) $paymentRequest->getHash(),
                'exception' => $exception,
            ]);

            $this->finalize($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);
        }
    }

    private function handle(PaymentRequestInterface $paymentRequest): void
    {
        $notification = $this->notificationVerifier->verify(
            $this->credentialsProvider->fromPaymentMethod($paymentRequest->getMethod()),
            $this->receivedParameters($paymentRequest),
        );

        if (!$notification->macValid) {
            // Nothing exploitable in the context: neither the payload, nor what would have made
            // the signature acceptable. The source address and the reason are enough to operate on.
            $this->logger->warning(
                $notification->signalsSignatureMismatch()
                    ? 'Axepta notification rejected on a signature code: check the configured HMAC key, a rotation may have been applied on the bank side and not here.'
                    : 'Axepta notification rejected, no state changed.',
                [
                    'payment_request_hash' => (string) $paymentRequest->getHash(),
                    'reason' => $notification->rejection?->value,
                    'code' => $notification->code(),
                    'transaction_id' => $notification->transactionId(),
                    'client_ip' => $this->clientIp($paymentRequest),
                ],
            );

            $this->finalize($paymentRequest, PaymentRequestTransitions::TRANSITION_FAIL);

            return;
        }

        // What was authenticated, kept for bank reconciliation.
        $paymentRequest->setResponseData([
            'status' => $notification->status?->value,
            'code' => $notification->code(),
            'transaction_id' => $notification->transactionId(),
            'pay_id' => $notification->payId(),
            'xid' => $notification->xid(),
        ]);

        // The payment arrives from the association as a Doctrine proxy, which the state machine
        // ties to no graph: without this resolution, `can()` answers false and the transition is
        // silently skipped. Sylius does the same in its own handlers.
        $this->proxyObjectResolver->resolve($paymentRequest);

        $payment = $paymentRequest->getPayment();

        // A status outside the enum is not a failure: the enum is not exhaustive from the
        // platform's point of view, and it may introduce values without notice. Marking as refused
        // a payment that may have been taken would be the more destructive of the two behaviours.
        if (null === $notification->status) {
            $this->logger->warning('Axepta notification with an unknown status: no state changed.', [
                'payment_request_hash' => (string) $paymentRequest->getHash(),
                'payment_id' => $payment->getId(),
                'payment_state' => $payment->getState(),
                'code' => $notification->code(),
                'xid' => $notification->xid(),
            ]);

            $this->finalize($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);

            return;
        }

        $transition = $notification->isPaid()
            ? PaymentTransitions::TRANSITION_COMPLETE
            : PaymentTransitions::TRANSITION_FAIL;

        // The guard makes a double notification harmless - that is the nominal case at BNP, not
        // the exception. Both outcomes are logged distinctly: without that, nothing lets an
        // operator tell a payment that has just settled from its twelfth retry.
        $applicable = $this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition);

        if ($applicable) {
            $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);
        }

        $this->logger->info(
            $applicable
                ? 'Axepta notification applied.'
                : 'Axepta notification with no effect: the transition no longer applies.',
            [
                'payment_request_hash' => (string) $paymentRequest->getHash(),
                'payment_id' => $payment->getId(),
                'payment_state' => $payment->getState(),
                'transition' => $transition,
                'status' => $notification->status->value,
                'code' => $notification->code(),
                'xid' => $notification->xid(),
            ],
        );

        // The request is handled whether the payment settled or not: `failed` here would mean
        // "unprocessable message", not "refused payment".
        $this->finalize($paymentRequest, PaymentRequestTransitions::TRANSITION_COMPLETE);
    }

    /**
     * The HTTP request body, as the framework stored it in the payload.
     *
     * @return array<string, mixed>
     */
    private function receivedParameters(PaymentRequestInterface $paymentRequest): array
    {
        $httpRequest = $this->httpRequest($paymentRequest);

        /** @var array<string, mixed> $request */
        $request = \is_array($httpRequest['request'] ?? null) ? $httpRequest['request'] : [];

        return $request;
    }

    private function clientIp(PaymentRequestInterface $paymentRequest): ?string
    {
        $clientIp = $this->httpRequest($paymentRequest)['clientIp'] ?? null;

        return \is_string($clientIp) ? $clientIp : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpRequest(PaymentRequestInterface $paymentRequest): array
    {
        $payload = $paymentRequest->getPayload();
        if (!\is_array($payload) || !\is_array($payload['http_request'] ?? null)) {
            return [];
        }

        /** @var array<string, mixed> $httpRequest */
        $httpRequest = $payload['http_request'];

        return $httpRequest;
    }

    private function finalize(PaymentRequestInterface $paymentRequest, string $transition): void
    {
        if ($this->stateMachine->can($paymentRequest, PaymentRequestTransitions::GRAPH, $transition)) {
            $this->stateMachine->apply($paymentRequest, PaymentRequestTransitions::GRAPH, $transition);
        }
    }
}
