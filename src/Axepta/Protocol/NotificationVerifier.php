<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\UndecipherableNotificationException;

/**
 * Authenticates a server-to-server notification and extracts its status.
 *
 * **Never** throws: an unreadable notification comes back as {@see Notification::rejected()}. This
 * is deliberate - an exception surfacing as a 500 on the notify URL would trigger 8 BNP retries
 * spread over ~21 h 36.
 */
final readonly class NotificationVerifier
{
    /** The platform emits `application/x-www-form-urlencoded; charset=iso-8859-1`. */
    private const PAYLOAD_ENCODING = 'ISO-8859-1';

    /**
     * Size cap of the `Data` field, in hexadecimal characters.
     *
     * The notification URL is open to anyone: the signature can only be verified after decryption,
     * so **anyone can put the decipherer to work without holding a key**. Blowfish here is pure
     * PHP, hence costly: measured in this project's container, 2 MB of `Data` burn close to a
     * second of CPU, and a `post_max_size` of 64 MB would open onto some fifteen seconds per
     * request. A handful of concurrent requests would then saturate the workers - and legitimate
     * notifications would no longer be processed.
     *
     * 8 KB leave thirty times the headroom of a real notification, which weighs a few hundred
     * bytes. The cap applies **before** `hex2bin`, hence before any allocation.
     */
    private const MAX_DATA_LENGTH = 8192;

    /**
     * Canonical names of the known parameters, indexed in lowercase.
     *
     * BNP's documentation explicitly asks to compare names case-insensitively, not to rely on their
     * order, and to tolerate new parameters appearing: those absent from this table are kept as is
     * rather than rejected.
     */
    private const CANONICAL_NAMES = [
        'mid' => 'MerchantID',
        'merchantid' => 'MerchantID',
        'payid' => 'PayID',
        'transid' => 'TransID',
        'xid' => 'XID',
        'status' => 'Status',
        'code' => 'Code',
        'mac' => 'MAC',
        'data' => 'Data',
        'len' => 'Len',
        'refnr' => 'RefNr',
        'amount' => 'Amount',
        'currency' => 'Currency',
        'description' => 'Description',
        'pcnr' => 'PCNr',
    ];

    /**
     * @param array<string, mixed> $requestParameters raw parameters of the incoming request
     */
    public function verify(AxeptaCredentials $credentials, array $requestParameters): Notification
    {
        $parameters = $this->canonicalize($requestParameters);

        if (isset($parameters['Data'])) {
            try {
                // The encrypted payload takes precedence over the clear parameters: only the
                // former are authenticated by the MAC, and letting a clear `Status` overwrite them
                // would be an open door.
                $parameters = array_merge($parameters, $this->decipher(
                    $credentials,
                    $parameters['Data'],
                    isset($parameters['Len']) ? (int) $parameters['Len'] : null,
                ));
            } catch (\Throwable) {
                // Deliberately wide net: anything can land on `Data` - truncation, a substituted
                // cipher implementation, random bytes from a scanner - and letting anything through
                // would produce a 500, hence 8 BNP retries over ~21 h 36. An unreadable payload is
                // a non-event, not a failure.
                return Notification::rejected(NotificationRejection::Undecipherable);
            }
        }

        $rejection = $this->reasonToReject($credentials, $parameters);
        if (null !== $rejection) {
            return Notification::rejected($rejection, $parameters);
        }

        return new Notification(true, AxeptaStatus::tryFromNotification($parameters['Status'] ?? null), $parameters);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function reasonToReject(AxeptaCredentials $credentials, array $parameters): ?NotificationRejection
    {
        // BNP requires the signed `MerchantID` to be the one from the request: if present and
        // different from ours, the message is not addressed to us, whatever its MAC.
        $merchantId = $parameters['MerchantID'] ?? null;
        if (null !== $merchantId && $merchantId !== $credentials->merchantId) {
            return NotificationRejection::ForeignMerchant;
        }

        $mac = $parameters['MAC'] ?? '';
        if ('' === $mac) {
            return NotificationRejection::MissingSignature;
        }

        // The signed `MerchantID` is the one from **our** configuration, never the received one:
        // the platform does not return it reliably in the payload, and relying on the received
        // value would let an attacker pick their own.
        $isAuthentic = $credentials->signer()->verify([
            $parameters['PayID'] ?? '',
            $parameters['TransID'] ?? '',
            $credentials->merchantId,
            $parameters['Status'] ?? '',
            $parameters['Code'] ?? '',
        ], $mac);

        return $isAuthentic ? null : NotificationRejection::InvalidSignature;
    }

    /**
     * @return array<string, string>
     *
     * @throws UndecipherableNotificationException
     */
    private function decipher(AxeptaCredentials $credentials, string $data, ?int $announcedLength = null): array
    {
        if (\strlen($data) > self::MAX_DATA_LENGTH) {
            throw UndecipherableNotificationException::oversized(\strlen($data), self::MAX_DATA_LENGTH);
        }

        $binary = @hex2bin($data);
        if (false === $binary || '' === $binary) {
            throw UndecipherableNotificationException::notHexadecimal();
        }

        $payload = $credentials->cipher()->decrypt($binary);
        if ('' === $payload) {
            throw UndecipherableNotificationException::emptyPayload();
        }

        // The bank announces the plaintext length: we stick to it rather than guess. The
        // decipherer strips trailing null bytes **and spaces** - an observed Axepta convention,
        // without which nothing reads back - so a signed field ending in a space would be clipped,
        // the MAC computed on truncated content, and a payment taken never recorded. `Len` exists
        // precisely to avoid that guesswork.
        if (null !== $announcedLength && $announcedLength > \strlen($payload)) {
            $payload = str_pad($payload, $announcedLength, ' ');
        }

        $payload = (string) mb_convert_encoding($payload, 'UTF-8', self::PAYLOAD_ENCODING);

        $fields = [];
        foreach (explode('&', $payload) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);
            $fields[$name] = $value;
        }

        return $this->canonicalize($fields);
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, string>
     */
    private function canonicalize(array $parameters): array
    {
        $canonical = [];
        foreach ($parameters as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $canonical[self::CANONICAL_NAMES[strtolower($name)] ?? $name] = (string) $value;
        }

        return $canonical;
    }
}
