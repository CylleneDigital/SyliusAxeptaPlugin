<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Crypto;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\HmacSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HmacSignerTest extends TestCase
{
    /** Key of the vectors published by BNP. Short, but it is the documented one. */
    private const OFFICIAL_KEY = 'mySecret';

    private const KEY = 's3cr3t-hmac-key-for-axepta-tests-0123456789';

    /**
     * Official vectors from BNP's documentation: they validate the algorithm against an
     * **external** reference, which no round-trip test can do. They cover both edge cases of the
     * format along the way - an empty leading value (`PayID` on the way out) and empty trailing
     * values - and confirm the platform publishes its MACs in uppercase hexadecimal.
     *
     * @return iterable<string, array{list<string>, string}>
     */
    public static function officialVectors(): iterable
    {
        yield 'request' => [
            ['', 'TID-4453732122167114558', 'YourMerchantID', '1234', 'EUR'],
            '0522F1AF6A88597D396A5A877499F3C9087EBCF103B1B47D7E4D13421CC7EA36',
        ];

        yield 'request without TransID' => [
            ['', '', 'YourMerchantID', '1234', 'EUR'],
            '1427748D983478080F22BE0878BD99AF7BE3E1C4B19C07AFD1B372BA552ADC08',
        ];

        yield 'request without amount or currency' => [
            ['fe3f002e19814eea8aa733ec4fdacafe', 'TID-4453732122167114558', 'YourMerchantID', '', ''],
            '6ED0CFDCE92CE13399552C4221B44E5B036DE943D7F84E33D1E73DF9871AE7C8',
        ];

        yield 'authorised notification' => [
            ['7bbb448155234d8cbee323778952ce28', 'TID-12033175321270170232', 'YourMerchantID', 'AUTHORIZED', '00000000'],
            'F1DE7608013C1E3FD3CC9964A049E26703137C0A6F29448545C700B4695EABE5',
        ];

        yield 'failed notification' => [
            ['7bbb448155234d8cbee323778952ce28', 'TID-12033175321270170232', 'YourMerchantID', 'FAILED', '22720040'],
            '1D9A8AAA306316359B8192070237670950DB77073F9F34ED7EB483D9B59DE1DD',
        ];
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('officialVectors')]
    public function testMatchesOfficialVector(array $values, string $expected): void
    {
        self::assertSame(strtolower($expected), (new HmacSigner(self::OFFICIAL_KEY))->sign($values));
    }

    /**
     * @param list<string> $values
     */
    #[DataProvider('officialVectors')]
    public function testVerifiesOfficialVectorInItsPublishedCase(array $values, string $expected): void
    {
        self::assertTrue((new HmacSigner(self::OFFICIAL_KEY))->verify($values, $expected));
    }

    /** A missing value stays an empty string, the separator remains. */
    public function testEmptyValuesKeepSeparators(): void
    {
        $signer = new HmacSigner(self::KEY);

        self::assertNotSame(
            $signer->sign(['', '12345', 'BNP_test', '4200', 'EUR']),
            $signer->sign(['12345', 'BNP_test', '4200', 'EUR']),
        );
    }

    public function testVerifyAcceptsValidMac(): void
    {
        $signer = new HmacSigner(self::KEY);
        $values = ['PayID', '12345', 'BNP_test', 'OK', '00000000'];

        self::assertTrue($signer->verify($values, $signer->sign($values)));
    }

    /** Regression guard: BNP returns the MAC in uppercase, `hash_hmac` produces lowercase. */
    public function testVerifyAcceptsUppercaseMac(): void
    {
        $signer = new HmacSigner(self::KEY);
        $values = ['PayID', '12345', 'BNP_test', 'OK', '00000000'];

        self::assertTrue($signer->verify($values, strtoupper($signer->sign($values))));
    }

    public function testVerifyRejectsTamperedStatus(): void
    {
        $signer = new HmacSigner(self::KEY);
        $mac = $signer->sign(['PayID', '12345', 'BNP_test', 'OK', '00000000']);

        self::assertFalse($signer->verify(['PayID', '12345', 'BNP_test', 'FAILED', '00000000'], $mac));
    }

    public function testVerifyRejectsEmptyMac(): void
    {
        $signer = new HmacSigner(self::KEY);

        self::assertFalse($signer->verify(['PayID', '12345', 'BNP_test', 'OK', '00000000'], ''));
    }

    public function testDifferentKeysProduceDifferentMacs(): void
    {
        $values = ['PayID', '12345', 'BNP_test', 'OK', '00000000'];

        self::assertNotSame(
            (new HmacSigner(self::KEY))->sign($values),
            (new HmacSigner(self::OFFICIAL_KEY))->sign($values),
        );
    }
}
