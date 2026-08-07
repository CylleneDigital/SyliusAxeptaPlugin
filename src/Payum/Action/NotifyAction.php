<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Payum\Action;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\Notification;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Server-to-server notification: authenticates the message and advances the payment.
 *
 * ⚠️ **This action must never let an exception surface.** A 404 or a 500 on the notify URL triggers
 * 8 BNP retries spread over ~21 h 36 - for a transient application bug just as for an
 * already-handled payment. Every failure is therefore caught and logged, and the request ends
 * normally.
 *
 * Three behaviours follow:
 * - MAC not genuine → no transition, logged as `warning`;
 * - payment accepted → `complete` transition, guarded by `can()`: a double notification, the
 *   nominal case at BNP, is then a no-op;
 * - payment refused → `fail` transition, which lets the customer retry from their order.
 *
 * @internal
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    private AxeptaCredentials $credentials;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly StateMachineInterface $stateMachine,
        private readonly NotificationVerifier $notificationVerifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function setApi($api): void
    {
        if (!$api instanceof AxeptaCredentials) {
            throw new UnsupportedApiException(\sprintf('Expected an %s instance.', AxeptaCredentials::class));
        }

        $this->credentials = $api;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        \assert($request instanceof Notify);

        $payment = $request->getFirstModel();
        \assert($payment instanceof PaymentInterface);

        try {
            $this->handle($payment, ArrayObject::ensureArrayObject($request->getModel()));
        } catch (\Throwable $exception) {
            // The payment stays in its state; that is repairable by hand or by a retry. A 500
            // here would not be: it would make the BNP outbox unusable for a day.
            //
            // The identifier is read back off the raw model, never off the typed variable: if the
            // caught failure was precisely a `TypeError` on the model type, dereferencing it here
            // would rethrow from inside the net meant to absorb it.
            $model = $request->getFirstModel();

            $this->logger->error('Handling an Axepta notification failed.', [
                'payment_id' => $model instanceof PaymentInterface ? $model->getId() : null,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * The model is constrained **here**, not only by the assertion in `execute()`.
     *
     * `zend.assertions` is `-1` in production: the assertion disappears there, so it cannot serve
     * as a guard. An unexpected model would produce a `TypeError` whose catch-all would in turn
     * dereference the payment - a 500 on the notification URL, precisely what this class exists to
     * avoid. The development container, for its part, enables assertions and would mask the defect.
     *
     * This is the same fix as the one applied to `StatusAction`, and the assertion stays for what
     * it is actually good at: documenting the invariant and checking it in development.
     */
    public function supports($request): bool
    {
        return $request instanceof Notify &&
            $request->getModel() instanceof \ArrayAccess &&
            $request->getFirstModel() instanceof PaymentInterface;
    }

    private function handle(PaymentInterface $payment, ArrayObject $details): void
    {
        $httpRequest = $this->requestStack->getCurrentRequest();

        // Accepted limitation of v1: cards notify over POST. Alternative payment means notify
        // over GET and are not in scope yet.
        if (null === $httpRequest || !$httpRequest->isMethod('POST')) {
            return;
        }

        // Symfony 7 annotates `all()` as `array<string, mixed>`; 6.4 returns a bare `array`,
        // which static analysis refuses to pass to the verifier.
        /** @var array<string, mixed> $requestParameters */
        $requestParameters = $httpRequest->request->all();

        $notification = $this->notificationVerifier->verify($this->credentials, $requestParameters);

        if (!$notification->macValid) {
            $this->logRejectedNotification($payment, $notification, $httpRequest->getClientIp());

            return;
        }

        // Defence in depth: the notification must carry the `TransID` that *this* payment
        // emitted.
        //
        // Nothing requires it today: the notification token is random and travels inside the
        // encrypted payload, so nobody knows another payment's notify URL. That is the **only**
        // barrier, and it would fall the day this path moved to a fixed URL like the other one. A
        // genuine message captured on a €1 order would then be good for a €5,000 one, the response
        // signature covering neither the amount nor the currency.
        $expected = $details['transactionId'] ?? null;
        $received = $notification->transactionId();

        if (\is_string($expected) && '' !== $expected && $expected !== $received) {
            $this->logger->warning('Axepta notification tied to the wrong payment: ignored.', [
                'payment_id' => $payment->getId(),
                'expected_transaction_id' => $expected,
                'received_transaction_id' => $received,
            ]);

            return;
        }

        $transition = $this->transitionFor($notification);

        if (null === $transition) {
            $this->logUnknownStatus($payment, $notification);

            return;
        }

        if (!$this->stateMachine->can($payment, PaymentTransitions::GRAPH, $transition)) {
            $this->logger->info('Axepta notification with no effect: the transition no longer applies.', [
                'payment_id' => $payment->getId(),
                'payment_state' => $payment->getState(),
                'transition' => $transition,
            ]);

            return;
        }

        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, $transition);

        $this->logger->info('Axepta notification applied.', [
            'payment_id' => $payment->getId(),
            'transition' => $transition,
            'status' => $notification->status?->value,
            'code' => $notification->code(),
            // Reconciliation key with the BNP back office in case of dispute.
            'xid' => $notification->xid(),
            'pay_id' => $notification->payId(),
        ]);
    }

    /**
     * Logs a rejection without disclosing anything exploitable: neither the payload, nor its
     * useful length, nor the slightest hint of what would have made the signature acceptable.
     */
    /**
     * The transition to apply, or `null` if the bank used a status we do not know.
     *
     * The status enum is **not** exhaustive from the platform's point of view, and it may introduce
     * values without notice. Treating the unknown as a failure would amount to marking as refused a
     * payment that may have been taken - the more destructive of the two possible behaviours. So we
     * touch nothing, and we report it: the bank's retry, or a manual recovery, both stay open.
     */
    private function transitionFor(Notification $notification): ?string
    {
        if (null === $notification->status) {
            return null;
        }

        return $notification->isPaid()
            ? PaymentTransitions::TRANSITION_COMPLETE
            : PaymentTransitions::TRANSITION_FAIL;
    }

    private function logUnknownStatus(PaymentInterface $payment, Notification $notification): void
    {
        $this->logger->warning('Axepta notification with an unknown status: no state changed.', [
            'payment_id' => $payment->getId(),
            'payment_state' => $payment->getState(),
            'code' => $notification->code(),
            'xid' => $notification->xid(),
        ]);
    }

    private function logRejectedNotification(PaymentInterface $payment, Notification $notification, ?string $clientIp): void
    {
        $this->logger->warning(
            $notification->signalsSignatureMismatch()
                ? 'Axepta notification rejected on a signature code: check the configured HMAC key, a rotation may have been applied on the bank side and not here.'
                : 'Axepta notification rejected, no state changed.',
            [
                'payment_id' => $payment->getId(),
                'reason' => $notification->rejection?->value,
                'code' => $notification->code(),
                'transaction_id' => $notification->transactionId(),
                'client_ip' => $clientIp,
            ],
        );
    }
}
