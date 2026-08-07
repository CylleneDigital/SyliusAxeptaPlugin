<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto;

final class BlowfishEcbFactory implements BlowfishEcbFactoryInterface
{
    public function create(#[\SensitiveParameter] string $key): BlowfishEcbInterface
    {
        return new BlowfishEcb($key);
    }
}
