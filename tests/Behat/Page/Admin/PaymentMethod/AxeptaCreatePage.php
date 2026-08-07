<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod;

use Sylius\Behat\Page\Admin\PaymentMethod\CreatePage;

/**
 * Axepta configuration fields on the creation form.
 *
 * The identifiers follow the gateway configuration form convention:
 * `sylius_admin_payment_method_gatewayConfig_config_<key>`, where `<key>` is the name stored in
 * `sylius_gateway_config.config` - hence an invariant.
 */
final class AxeptaCreatePage extends CreatePage implements AxeptaCreatePageInterface
{
    public function specifyMerchantId(string $merchantId): void
    {
        $this->getElement('merchant_id')->setValue($merchantId);
    }

    public function specifyHmacKey(#[\SensitiveParameter] string $hmacKey): void
    {
        $this->getElement('hmac_key')->setValue($hmacKey);
    }

    public function specifyBlowfishKey(#[\SensitiveParameter] string $blowfishKey): void
    {
        $this->getElement('blowfish_key')->setValue($blowfishKey);
    }

    /**
     * @return array<string, string>
     */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'merchant_id' => '#sylius_admin_payment_method_gatewayConfig_config_merchant_id',
            'hmac_key' => '#sylius_admin_payment_method_gatewayConfig_config_hmac_key',
            'blowfish_key' => '#sylius_admin_payment_method_gatewayConfig_config_blowfish_key',
            'test_mode' => '#sylius_admin_payment_method_gatewayConfig_config_test_mode',
        ]);
    }
}
