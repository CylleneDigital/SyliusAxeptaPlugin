<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto;

/**
 * Builds the cipher from a key.
 *
 * The indirection exists because the Blowfish key is not known at wiring time: it is carried by the
 * payment method. **This factory** is therefore what an integrator aliases in their container to
 * substitute a native implementation of {@see BlowfishEcbInterface}.
 */
interface BlowfishEcbFactoryInterface
{
    public function create(#[\SensitiveParameter] string $key): BlowfishEcbInterface;
}
