<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod;

use Sylius\Behat\Page\Admin\PaymentMethod\UpdatePageInterface;

interface AxeptaUpdatePageInterface extends UpdatePageInterface
{
    public function enableTestMode(): void;

    public function isInTestMode(): bool;

    /** Does the field still carry a value after being redisplayed? */
    public function hasKeyKept(string $field): bool;
}
