<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\CommandHandler;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestBuilder;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestContext;
use CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Command\CaptureAxeptaPaymentRequest;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use CylleneDigital\SyliusAxeptaPlugin\Provider\ReturnUrlProvider;
use Psr\Log\LoggerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PaymentBundle\Provider\PaymentRequestProviderInterface;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the payment request and stores it in the response data, from where
 * `AxeptaHttpResponseProvider` picks it up to render the redirect page.
 *
 * The request moves to `processing`, not `completed`: off-site, the capture only redirects. The
 * `complete` only comes with the notification.
 *
 * @experimental
 *
 * @internal
 */
#[AsMessageHandler(bus: 'sylius.payment_request.command_bus')]
final readonly class CaptureAxeptaPaymentRequestHandler
{
    public function __construct(
        private PaymentRequestProviderInterface $paymentRequestProvider,
        private CredentialsProvider $credentialsProvider,
        private PaymentPageRequestBuilder $requestBuilder,
        private PaymentDescriptionProviderInterface $paymentDescriptionProvider,
        private StateMachineInterface $stateMachine,
        private UrlGeneratorInterface $urlGenerator,
        private ReturnUrlProvider $returnUrlProvider,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CaptureAxeptaPaymentRequest $command): void
    {
        $paymentRequest = $this->paymentRequestProvider->provide($command);

        $payment = $paymentRequest->getPayment();
        \assert($payment instanceof PaymentInterface);

        $order = $payment->getOrder();
        \assert($order instanceof OrderInterface);

        $paymentMethod = $paymentRequest->getMethod();
        $settings = $this->settings($paymentMethod->getGatewayConfig());

        // Both adapters target the same route: where the customer lands does not depend on the
        // payment mechanism chosen. See {@see ReturnUrlProvider} for the alphabet constraint.
        $returnUrl = $this->returnUrlProvider->forPayment($payment);

        $request = $this->requestBuilder->build(
            $this->credentialsProvider->fromPaymentMethod($paymentMethod),
            new PaymentPageRequestContext(
                amountInCents: (int) $payment->getAmount(),
                currency: (string) $payment->getCurrencyCode(),
                paymentIdentifier: $this->identifierOf($payment),
                reference: (string) $order->getNumber(),
                orderDescription: $this->paymentDescriptionProvider->getPaymentDescription($payment),
                successUrl: $returnUrl,
                failureUrl: $returnUrl,
                // Fixed URL, carrying the payment method code: every notification creates a
                // fresh request there under the `notify` action. The other available route, keyed
                // on this request's hash, would return a 404 as soon as it reached a final state -
                // hence 8 BNP retries over ~21 h 36 on the slightest double notification.
                notifyUrl: $this->urlGenerator->generate(
                    'sylius_payment_method_notify',
                    ['code' => (string) $paymentMethod->getCode()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                backUrl: $returnUrl,
                locale: $settings['language'] ?? $order->getLocaleCode(),
                testMode: $settings['test_mode'],
            ),
        );

        $paymentRequest->setResponseData([
            'url' => $request->url,
            'fields' => $request->fields,
        ]);

        $this->stateMachine->apply(
            $paymentRequest,
            PaymentRequestTransitions::GRAPH,
            PaymentRequestTransitions::TRANSITION_PROCESS,
        );

        // Never `Data` nor `MAC`: the encrypted payload carries the signature, a value derived
        // from the shared secret.
        $this->logger->info('Axepta payment request built.', [
            'payment_request_hash' => (string) $paymentRequest->getHash(),
            'payment_id' => $payment->getId(),
            'transaction_id' => $request->transactionId(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrencyCode(),
            'test_mode' => $settings['test_mode'],
        ]);
    }

    /**
     * @return array{test_mode: bool, language: string|null}
     */
    private function settings(?GatewayConfigInterface $gatewayConfig): array
    {
        $config = $gatewayConfig?->getConfig() ?? [];
        $language = $config['language'] ?? null;

        return [
            'test_mode' => (bool) ($config['test_mode'] ?? false),
            'language' => \is_string($language) && '' !== $language ? $language : null,
        ];
    }

    /** The identifier of a Sylius resource is typed `mixed`: it is narrowed explicitly. */
    private function identifierOf(PaymentInterface $payment): string
    {
        $id = $payment->getId();

        return \is_scalar($id) ? (string) $id : '';
    }
}
