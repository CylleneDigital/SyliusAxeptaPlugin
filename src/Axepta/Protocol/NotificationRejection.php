<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * Why a notification was not authenticated.
 *
 * The distinction is operational, not decorative: an unreadable payload suggests a scanner or a
 * truncated request, a wrong signature suggests a badly sequenced key rotation, and a foreign
 * merchant suggests a configuration mistake. The three call for different responses, and only the
 * log tells them apart - the message is rejected in all three cases.
 */
enum NotificationRejection: string
{
    /** The `Data` field could not be reduced to a usable payload. */
    case Undecipherable = 'undecipherable';

    /** No signature in the message. */
    case MissingSignature = 'missing_signature';

    /** Signature present but not matching the content. */
    case InvalidSignature = 'invalid_signature';

    /** Message addressed to another merchant identifier. */
    case ForeignMerchant = 'foreign_merchant';

    /**
     * Is a wrong signature attributable to the configured key?
     *
     * The first two cases are internet noise; the last two deserve a look at the configuration
     * before concluding it is an attack.
     */
    public function suggestsMisconfiguration(): bool
    {
        return self::InvalidSignature === $this || self::ForeignMerchant === $this;
    }
}
