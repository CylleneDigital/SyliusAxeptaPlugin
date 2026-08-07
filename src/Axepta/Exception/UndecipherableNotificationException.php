<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception;

/**
 * The `Data` field of a notification could not be reduced to a usable payload.
 *
 * Internal channel: {@see \CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier}
 * catches it and returns an invalid notification. An unreadable notification must **never** surface
 * as a 500 on the notify URL - BNP would answer with 8 retries spread over ~21 h 36.
 */
final class UndecipherableNotificationException extends \RuntimeException implements AxeptaException
{
    public static function notHexadecimal(): self
    {
        return new self('The "Data" field of the notification is not valid hexadecimal.');
    }

    public static function emptyPayload(): self
    {
        return new self('Decrypting the "Data" field produced an empty payload.');
    }

    public static function oversized(int $length, int $maximum): self
    {
        return new self(\sprintf(
            'The "Data" field is %d characters long, past the %d cap: it is not decrypted.',
            $length,
            $maximum,
        ));
    }
}
