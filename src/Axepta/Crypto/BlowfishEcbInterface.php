<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto;

/**
 * Blowfish-ECB encryption following the Axepta conventions.
 *
 * Exposed so that an integrator who has a native implementation - a PHP extension built with the
 * OpenSSL legacy provider, an HSM - can substitute it without forking the plugin. Substitution
 * happens on {@see BlowfishEcbFactoryInterface}, since the key is only known at runtime. The
 * shipped implementation is {@see BlowfishEcb}.
 *
 * Any implementation must honour the three conventions of the protocol: key repeated up to
 * 16 bytes, plaintext padded with null bytes up to a multiple of 8, and trailing null bytes **and
 * spaces** stripped on decryption, BNP padding with spaces.
 */
interface BlowfishEcbInterface
{
    /** Encrypts and returns raw binary, not hexadecimal. */
    public function encrypt(string $data): string;

    /** Decrypts raw binary and strips the trailing padding. */
    public function decrypt(string $data): string;
}
