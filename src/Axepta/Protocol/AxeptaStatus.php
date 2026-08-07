<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * Values of the `Status` field of an Axepta notification.
 *
 * `OK` and `AUTHORIZED` arrive through `URLSuccess`, `FAILED` through `URLFailure`. The failure
 * value really is `FAILED`, not `FAILURE`.
 *
 * The enum is **not** exhaustive from the platform's point of view: BNP may introduce new values
 * without notice. An unknown status maps to `null`, hence to an unsettled payment, never to an
 * error - the actionable detail is in `Code` anyway.
 */
enum AxeptaStatus: string
{
    case Ok = 'OK';
    case Authorized = 'AUTHORIZED';
    case Failed = 'FAILED';

    /** Tolerates case and whitespace: BNP's documentation asks not to rely on the received form. */
    public static function tryFromNotification(?string $status): ?self
    {
        if (null === $status) {
            return null;
        }

        return self::tryFrom(strtoupper(trim($status)));
    }

    public function isSuccessful(): bool
    {
        return self::Ok === $this || self::Authorized === $this;
    }
}
