<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Payum\Action;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestBuilder;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestContext;
use CylleneDigital\SyliusAxeptaPlugin\Provider\ReturnUrlProvider;
use CylleneDigital\SyliusAxeptaPlugin\Renderer\AutoSubmitFormRenderer;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Payum;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Capture;
use Payum\Core\Security\TokenInterface;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Off-site capture: builds the signed request and redirects the customer to the payment page
 * hosted by the bank, by way of a self-submitting form.
 *
 * The notify URL is a Payum token, which ties the server-to-server notification back to the right
 * payment. The action is idempotent: an already completed payment triggers nothing, and the
 * `TransID` already assigned is reused rather than opening one more transaction at BNP.
 *
 * @internal
 */
final class CaptureAction implements ActionInterface, ApiAwareInterface
{
    private AxeptaCredentials $credentials;

    public function __construct(
        private readonly Payum $payum,
        private readonly ReturnUrlProvider $returnUrlProvider,
        private readonly PaymentPageRequestBuilder $requestBuilder,
        private readonly PaymentDescriptionProviderInterface $paymentDescriptionProvider,
        private readonly AutoSubmitFormRenderer $formRenderer,
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
        \assert($request instanceof Capture);

        $payment = $request->getFirstModel();
        \assert($payment instanceof PaymentInterface);

        if (PaymentInterface::STATE_COMPLETED === $payment->getState()) {
            return;
        }

        $order = $payment->getOrder();
        \assert($order instanceof OrderInterface);

        $token = $request->getToken();
        \assert($token instanceof TokenInterface);

        $details = ArrayObject::ensureArrayObject($request->getModel());

        $settings = $this->settings($payment);
        $existingTransactionId = $details['transactionId'] ?? null;

        // The plugin's own route, not Sylius' after-payment one: the latter carries a
        // single-use token that a second return from the bank exhausts. Its alphabet matters too,
        // see {@see \CylleneDigital\SyliusAxeptaPlugin\Provider\ReturnUrlProvider}.
        $returnUrl = $this->returnUrlProvider->forPayment($payment);

        $paymentPageRequest = $this->requestBuilder->build($this->credentials, new PaymentPageRequestContext(
            amountInCents: (int) $payment->getAmount(),
            currency: (string) $payment->getCurrencyCode(),
            paymentIdentifier: $this->identifierOf($payment),
            reference: (string) $order->getNumber(),
            orderDescription: $this->paymentDescriptionProvider->getPaymentDescription($payment),
            successUrl: $returnUrl,
            failureUrl: $returnUrl,
            notifyUrl: $this->createNotifyToken($token, $payment)->getTargetUrl(),
            backUrl: $returnUrl,
            // A language forced in the configuration wins; otherwise the payment page follows
            // the order locale.
            locale: $settings['language'] ?? $order->getLocaleCode(),
            testMode: $settings['test_mode'],
            transactionId: \is_string($existingTransactionId) && '' !== $existingTransactionId ? $existingTransactionId : null,
        ));

        $details['transactionId'] = $paymentPageRequest->transactionId();
        $request->setModel($details);

        // Never `Data` nor `MAC`: the encrypted payload carries the signature, a value derived
        // from the shared secret.
        $this->logger->info('Axepta payment request built.', [
            'payment_id' => $payment->getId(),
            'transaction_id' => $paymentPageRequest->transactionId(),
            'amount' => $payment->getAmount(),
            'currency' => $payment->getCurrencyCode(),
            'test_mode' => $settings['test_mode'],
        ]);

        throw new HttpResponse($this->formRenderer->render($paymentPageRequest));
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof \ArrayAccess;
    }

    /**
     * Both behaviour settings are read off the gateway configuration.
     *
     * They are absent from payment methods created before they were introduced: their absence must
     * stay silent, otherwise migrating would require a data migration.
     *
     * @return array{test_mode: bool, language: string|null}
     */
    private function settings(PaymentInterface $payment): array
    {
        $gatewayConfig = $payment->getMethod()?->getGatewayConfig();
        $config = $gatewayConfig instanceof GatewayConfigInterface ? $gatewayConfig->getConfig() : [];

        $language = $config['language'] ?? null;

        return [
            'test_mode' => (bool) ($config['test_mode'] ?? false),
            'language' => \is_string($language) && '' !== $language ? $language : null,
        ];
    }

    private function createNotifyToken(TokenInterface $token, object $model): TokenInterface
    {
        return $this->payum->getTokenFactory()->createNotifyToken($token->getGatewayName(), $model);
    }

    /** The identifier of a Sylius resource is typed `mixed`: it is narrowed explicitly. */
    private function identifierOf(PaymentInterface $payment): string
    {
        $id = $payment->getId();

        return \is_scalar($id) ? (string) $id : '';
    }
}
