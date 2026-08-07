<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Payum;

use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Payum\Core\GatewayFactoryInterface;

/**
 * Builds the gateway factory, handing it services from the container.
 *
 * Payum's `GatewayFactoryBuilder` only instantiates the class with `(array $defaultConfig,
 * GatewayFactoryInterface $coreGatewayFactory)`: without this builder, the factory would have
 * access neither to the substitutable cipher factory, nor to the payment page URL configured at
 * bundle level. `PayumBuilder::addGatewayFactory()` accepts any callable, and this is one.
 *
 * @internal
 */
final readonly class AxeptaGatewayFactoryBuilder
{
    public function __construct(private CredentialsProvider $credentialsProvider)
    {
    }

    /**
     * @param array<string, mixed> $defaultConfig
     */
    public function __invoke(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory): GatewayFactoryInterface
    {
        return new AxeptaGatewayFactory($this->credentialsProvider, $defaultConfig, $coreGatewayFactory);
    }
}
