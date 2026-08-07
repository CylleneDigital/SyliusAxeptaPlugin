<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Payum;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Payum\Core\GatewayFactoryInterface;

/**
 * Payum gateway factory "axepta".
 *
 * The factory name is an **invariant**: it is stored in `sylius_gateway_config.factory_name` of
 * existing payment methods. Renaming it would require a data migration.
 *
 * The configuration - merchant identifier and keys - is administered in the back office and stored
 * by Sylius, never in fixtures nor in the repository. The factory derives from it the
 * {@see AxeptaCredentials} injected into the actions through `ApiAwareInterface`.
 */
final class AxeptaGatewayFactory extends GatewayFactory
{
    public const NAME = 'axepta';

    /** @var list<string> */
    private const REQUIRED_OPTIONS = ['merchant_id', 'hmac_key', 'blowfish_key'];

    /**
     * @param array<string, mixed> $defaultConfig
     */
    public function __construct(
        private readonly CredentialsProvider $credentialsProvider,
        array $defaultConfig = [],
        ?GatewayFactoryInterface $coreGatewayFactory = null,
    ) {
        parent::__construct($defaultConfig, $coreGatewayFactory);
    }

    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => self::NAME,
            'payum.factory_title' => 'Axepta - BNP Paribas',
        ]);

        if (false !== ($config['payum.api'] ?? false)) {
            return;
        }

        $config->defaults(array_fill_keys(self::REQUIRED_OPTIONS, ''));
        $config['payum.required_options'] = self::REQUIRED_OPTIONS;

        $credentialsProvider = $this->credentialsProvider;

        $config['payum.api'] = static function (ArrayObject $config) use ($credentialsProvider): AxeptaCredentials {
            $config->validateNotEmpty(self::REQUIRED_OPTIONS);

            /** @var array<string, mixed> $options */
            $options = (array) $config;

            return $credentialsProvider->fromArray($options);
        };
    }
}
