<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception;

/**
 * A URL handed to the payment page request would cut the payload in two.
 *
 * Thrown before the payment leaves: a return URL truncated at the bank strands the customer on an
 * error after they have paid.
 */
final class UnusablePaymentPageUrlException extends \InvalidArgumentException implements AxeptaException
{
    public static function containsAmpersand(string $field): self
    {
        return new self(\sprintf(
            'The URL supplied for "%s" contains an "&", which would cut the Axepta payload. ' .
            'A return URL may carry only one query parameter.',
            $field,
        ));
    }
}
