<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod;

use Sylius\Behat\Page\Admin\PaymentMethod\CreatePageInterface;

interface AxeptaCreatePageInterface extends CreatePageInterface
{
    public function specifyMerchantId(string $merchantId): void;

    public function specifyHmacKey(#[\SensitiveParameter] string $hmacKey): void;

    public function specifyBlowfishKey(#[\SensitiveParameter] string $blowfishKey): void;
}
