<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\PaymentRequest\Provider;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequest;
use CylleneDigital\SyliusAxeptaPlugin\Renderer\AutoSubmitFormRenderer;
use Sylius\Bundle\PaymentBundle\Provider\HttpResponseProviderInterface;
use Sylius\Bundle\ResourceBundle\Controller\RequestConfiguration;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the redirect page from what the capture stored in the response data.
 *
 * It is the same template as on the Payum path: the customer sees the same page whichever
 * mechanism the shop settled on.
 *
 * @experimental
 *
 * @internal
 */
final readonly class AxeptaHttpResponseProvider implements HttpResponseProviderInterface
{
    public function __construct(private AutoSubmitFormRenderer $formRenderer)
    {
    }

    public function supports(RequestConfiguration $requestConfiguration, PaymentRequestInterface $paymentRequest): bool
    {
        return PaymentRequestInterface::ACTION_CAPTURE === $paymentRequest->getAction() &&
            [] !== $paymentRequest->getResponseData();
    }

    public function getResponse(RequestConfiguration $requestConfiguration, PaymentRequestInterface $paymentRequest): Response
    {
        $responseData = $paymentRequest->getResponseData();

        $url = $responseData['url'] ?? null;

        /** @var array<string, string>|null $fields */
        $fields = $responseData['fields'] ?? null;

        if (!\is_string($url) || !\is_array($fields)) {
            throw new \LogicException(\sprintf(
                'Payment request "%s" carries no usable payment page request.',
                (string) $paymentRequest->getHash(),
            ));
        }

        return new Response($this->formRenderer->render(new PaymentPageRequest($url, $fields)));
    }
}
