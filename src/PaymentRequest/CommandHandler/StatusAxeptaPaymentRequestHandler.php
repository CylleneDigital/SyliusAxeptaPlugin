<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler;

use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\StatusAxeptaPaymentRequest;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reports the current state of the payment in the response data.
 *
 * The bank is never queried: the truth comes from the notification. Querying the platform to
 * recover a definitively lost notification belongs to the `sync` action, in the backlog.
 *
 * @experimental
 *
 * @internal
 */
#[AsMessageHandler(bus: 'sylius.payment_request.command_bus')]
final readonly class StatusAxeptaPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private StateMachineInterface $stateMachine,
    ) {
    }

    public function __invoke(StatusAxeptaPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $paymentRequest->setResponseData([
            'payment_state' => $paymentRequest->getPayment()->getState(),
        ]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_COMPLETE,
        );
    }
}
