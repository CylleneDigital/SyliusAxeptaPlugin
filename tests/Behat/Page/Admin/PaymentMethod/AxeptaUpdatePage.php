<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod;

use Sylius\Behat\Page\Admin\PaymentMethod\UpdatePage;

/**
 * Axepta configuration fields on the edit form.
 *
 * {@see self::hasKeyKept()} guards the costliest regression risk of the plugin: the keys are
 * rendered as `PasswordType`, and without `always_empty => false` they would come back blank. A
 * plain save would then replace them with empty strings, and payments would fail with no visible
 * error afterwards - the signature being computed with an empty key.
 */
final class AxeptaUpdatePage extends UpdatePage implements AxeptaUpdatePageInterface
{
    public function enableTestMode(): void
    {
        $this->getElement('test_mode')->check();
    }

    public function isInTestMode(): bool
    {
        return $this->getElement('test_mode')->hasAttribute('checked');
    }

    public function hasKeyKept(string $field): bool
    {
        $value = $this->getElement($field)->getValue();

        return \is_string($value) && '' !== $value;
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
