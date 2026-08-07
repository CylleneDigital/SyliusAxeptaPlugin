<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception;

/**
 * Unusable gateway configuration - thrown when building the credentials, never in the middle of a
 * payment.
 *
 * The message **never** carries the offending value: a truncated key in a log is still a partial
 * secret leak.
 */
final class InvalidCredentialsException extends \InvalidArgumentException implements AxeptaException
{
    public static function emptyField(string $field): self
    {
        return new self(\sprintf('Incomplete Axepta configuration: "%s" is empty.', $field));
    }
}
