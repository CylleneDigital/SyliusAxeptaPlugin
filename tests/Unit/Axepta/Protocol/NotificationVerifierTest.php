<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Protocol;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\HmacSigner;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaStatus;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationRejection;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\NotificationVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationVerifierTest extends TestCase
{
    private const MERCHANT_ID = 'BNP_TEST_MERCHANT';

    private const HMAC_KEY = 's3cr3t-hmac-key-for-axepta-tests-0123456789';

    private const BLOWFISH_KEY = 'aB3dEf9hJk2mNp5q';

    private const PAY_ID = '7bbb448155234d8cbee323778952ce28';

    private const TRANSACTION_ID = 'sylius-42-0123456789abcdef';

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'OK' => ['OK', true];
        yield 'AUTHORIZED' => ['AUTHORIZED', true];
        yield 'FAILED' => ['FAILED', false];
    }

    #[DataProvider('statuses')]
    public function testAuthenticatesEveryDocumentedStatus(string $status, bool $paid): void
    {
        $notification = $this->verify($this->notify($status));

        self::assertTrue($notification->macValid, 'A genuine refusal has a valid MAC.');
        self::assertSame($status, $notification->status?->value);
        self::assertSame($paid, $notification->isPaid());
    }

    public function testExposesTheReconciliationIdentifiers(): void
    {
        $notification = $this->verify($this->notify('OK', extra: ['XID' => 'XID-4453732122167114558']));

        self::assertSame(self::PAY_ID, $notification->payId());
        self::assertSame(self::TRANSACTION_ID, $notification->transactionId());
        self::assertSame('XID-4453732122167114558', $notification->xid());
        self::assertSame('00000000', $notification->code());
    }

    public function testRejectsATamperedStatus(): void
    {
        // Payload signed on OK, then re-encrypted with a modified status: exactly what a
        // middleman able to replay a message without knowing the HMAC key would produce.
        $payload = str_replace('Status=OK', 'Status=AUTHORIZED', $this->payload('OK'));

        $notification = $this->verify(['Data' => $this->encrypt($payload)]);

        self::assertFalse($notification->macValid);
        self::assertFalse($notification->isPaid());
    }

    /**
     * Regression guard: the platform does not return the `MerchantID` reliably in the payload, so
     * the MAC is recomputed with the one from our configuration. A message announcing another one
     * is not addressed to us, whatever its signature.
     */
    public function testRejectsAForeignMerchant(): void
    {
        $payload = str_replace(self::MERCHANT_ID, 'SOMEONE_ELSE', $this->payload('OK'));

        $notification = $this->verify(['Data' => $this->encrypt($payload)]);

        self::assertFalse($notification->macValid);
        self::assertFalse($notification->isPaid());
    }

    /** BNP's documentation explicitly asks to compare parameter names case-insensitively. */
    public function testAcceptsAnyParameterCase(): void
    {
        $payload = \sprintf(
            'payid=%s&TRANSID=%s&merchantid=%s&Status=%s&cOdE=%s&mac=%s',
            self::PAY_ID,
            self::TRANSACTION_ID,
            self::MERCHANT_ID,
            'OK',
            '00000000',
            $this->mac('OK', '00000000'),
        );

        $notification = $this->verify(['Data' => $this->encrypt($payload)]);

        self::assertTrue($notification->macValid);
        self::assertTrue($notification->isPaid());
        self::assertSame(self::TRANSACTION_ID, $notification->transactionId());
    }

    /** The documentation states that new parameters may appear without notice. */
    public function testIgnoresUnknownParameters(): void
    {
        $notification = $this->verify($this->notify('OK', extra: ['SomeFutureField' => 'whatever']));

        self::assertTrue($notification->isPaid());
        self::assertSame('whatever', $notification->parameters['SomeFutureField'] ?? null);
    }

    public function testDecodesAccentedValuesFromLatin1(): void
    {
        $notification = $this->verify($this->notify('FAILED', code: '22720040', extra: [
            'Description' => 'Card refused by the issuer',
        ]));

        self::assertTrue($notification->macValid);
        self::assertSame('Card refused by the issuer', $notification->parameters['Description'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableNotifications(): iterable
    {
        yield 'non-hexadecimal Data' => [['Data' => 'pas-de-l-hexa']];
        yield 'truncated hexadecimal Data' => [['Data' => 'abcd']];
        yield 'Data vide' => [['Data' => '']];
        yield 'empty request' => [[]];
        yield 'without MAC' => [['Status' => 'OK', 'TransID' => self::TRANSACTION_ID]];
        yield 'MAC vide' => [['Status' => 'OK', 'MAC' => '']];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('unusableNotifications')]
    public function testNeverThrowsOnAnUnusableNotification(array $parameters): void
    {
        $notification = $this->verify($parameters);

        self::assertFalse($notification->macValid);
        self::assertFalse($notification->isPaid());
    }

    /** The `MID` received in clear means the same as `MerchantID`: we normalise it. */
    public function testNormalisesThePlainMidParameter(): void
    {
        $notification = $this->verify([
            'mid' => self::MERCHANT_ID,
            'PayID' => self::PAY_ID,
            'TransID' => self::TRANSACTION_ID,
            'Status' => 'OK',
            'Code' => '00000000',
            'MAC' => $this->mac('OK', '00000000'),
        ]);

        self::assertTrue($notification->macValid);
        self::assertSame(self::MERCHANT_ID, $notification->merchantId());
    }

    /**
     * Clear parameters are not authenticated: only those of the encrypted payload are. A clear
     * `Status` must therefore never overwrite the signed one.
     */
    public function testEncryptedPayloadWinsOverPlainParameters(): void
    {
        $notification = $this->verify($this->notify('FAILED', code: '22720040') + ['Status' => 'OK']);

        self::assertTrue($notification->macValid);
        self::assertSame(AxeptaStatus::Failed, $notification->status);
        self::assertFalse($notification->isPaid());
    }

    /** A status outside the enum counts as "not paid", never as an error. */
    public function testTreatsAnUnknownStatusAsUnpaid(): void
    {
        $notification = $this->verify($this->notify('PENDING', code: '12345678'));

        self::assertTrue($notification->macValid);
        self::assertNull($notification->status);
        self::assertFalse($notification->isPaid());
    }

    /**
     * The notification URL is open to anyone, and the signature can only be verified after
     * decryption: anyone can therefore put the decipherer to work without a key. Blowfish here is
     * pure PHP - 2 MB cost close to a second of CPU, and a handful of concurrent requests would
     * saturate the workers, hence prevent legitimate notifications from being processed.
     */
    public function testAnOversizedPayloadIsRejectedWithoutBeingDeciphered(): void
    {
        $start = microtime(true);
        $notification = $this->verify(['Data' => str_repeat('ab', 2 * 1024 * 1024)]);
        $elapsed = microtime(true) - $start;

        self::assertFalse($notification->macValid);
        self::assertSame(NotificationRejection::Undecipherable, $notification->rejection);
        self::assertLessThan(0.1, $elapsed, 'The payload was decrypted when it should have been discarded on its size.');
    }

    /**
     * The decipherer strips trailing spaces - an Axepta convention, without which nothing reads
     * back. A signed field ending in a space would therefore be clipped, the MAC computed on
     * truncated content, and a payment taken never recorded. `Len` exists to remove the guesswork.
     */
    public function testTheAnnouncedLengthRestoresTrailingSpaces(): void
    {
        // A `Code` ending in a space, placed last - and signed as such by the bank.
        $code = '00000000 ';
        $payload = implode('&', [
            'PayID=' . self::PAY_ID,
            'TransID=' . self::TRANSACTION_ID,
            'MerchantID=' . self::MERCHANT_ID,
            'MAC=' . $this->mac('OK', $code),
            'Status=OK',
            'Code=' . $code,
        ]);

        $withLength = $this->verify([
            'Data' => $this->encrypt($payload),
            'Len' => (string) \strlen($payload),
        ]);
        $withoutLength = $this->verify(['Data' => $this->encrypt($payload)]);

        self::assertTrue($withLength->macValid, 'The announced length must restore the trailing space.');
        self::assertFalse($withoutLength->macValid, 'Without it the field is clipped and the signature no longer matches.');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function verify(array $parameters): \CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\Notification
    {
        return (new NotificationVerifier())->verify($this->credentials(), $parameters);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function notify(string $status, string $code = '00000000', array $extra = []): array
    {
        return ['Data' => $this->encrypt($this->payload($status, $code, $extra))];
    }

    /**
     * @param array<string, string> $extra
     */
    private function payload(string $status, string $code = '00000000', array $extra = []): string
    {
        $fields = [
            'PayID' => self::PAY_ID,
            'TransID' => self::TRANSACTION_ID,
            'MerchantID' => self::MERCHANT_ID,
            'Status' => $status,
            'Code' => $code,
            'MAC' => $this->mac($status, $code),
        ] + $extra;

        $pairs = [];
        foreach ($fields as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('&', $pairs);
    }

    private function mac(string $status, string $code): string
    {
        return strtoupper((new HmacSigner(self::HMAC_KEY))->sign([
            self::PAY_ID,
            self::TRANSACTION_ID,
            self::MERCHANT_ID,
            $status,
            $code,
        ]));
    }

    /** The platform emits in ISO-8859-1: forged notifications must be too. */
    private function encrypt(string $payload): string
    {
        $latin1 = (string) mb_convert_encoding($payload, 'ISO-8859-1', 'UTF-8');

        return bin2hex($this->credentials()->cipher()->encrypt($latin1));
    }

    private function credentials(): AxeptaCredentials
    {
        return new AxeptaCredentials(self::MERCHANT_ID, self::HMAC_KEY, self::BLOWFISH_KEY);
    }
}
