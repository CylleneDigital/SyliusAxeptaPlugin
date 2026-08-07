<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Renderer;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequest;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Renders the transition page that posts the signed form to the hosted payment page.
 *
 * The template is overridable through the standard Sylius mechanism - `templates/bundles/`, themes.
 * This is the page the customer sees between the shop and the bank, so an integrator must be able
 * to style and translate it without forking the plugin.
 *
 * @internal
 */
final readonly class AutoSubmitFormRenderer
{
    public const TEMPLATE = '@CylleneDigitalSyliusAxeptaPlugin/shop/payment/axepta_redirect.html.twig';

    public function __construct(
        private Environment $twig,
        private string $template = self::TEMPLATE,
    ) {
    }

    /**
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function render(PaymentPageRequest $request): string
    {
        return $this->twig->render($this->template, [
            'url' => $request->url,
            'fields' => $request->fields,
        ]);
    }
}
