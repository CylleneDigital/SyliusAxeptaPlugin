<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception;

/**
 * The Blowfish cipher was given something it cannot work on.
 *
 * Neither message carries the offending value: a key, even empty or truncated, has no business in
 * a log.
 */
final class InvalidCipherInputException extends \InvalidArgumentException implements AxeptaException
{
    public static function emptyKey(): self
    {
        return new self('The Blowfish key cannot be empty.');
    }

    public static function invalidBlockSize(): self
    {
        return new self('Invalid Blowfish block, 8 bytes expected.');
    }
}
