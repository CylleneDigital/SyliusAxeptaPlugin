<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Crypto;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcb;
use PHPUnit\Framework\TestCase;

/**
 * Conformance of the Blowfish-ECB implementation against vectors produced by `openssl BF-ECB`
 * (legacy provider), including Eric Young's standard Blowfish vector. Were a single word of the
 * S-boxes or of the P-array wrong, these equalities would break.
 */
final class BlowfishEcbTest extends TestCase
{
    private const KEY = 'aB3dEf9hJk2mNp5q';

    /** Standard Blowfish vector: all-zero key and plaintext → 4ef997456198dd78. */
    public function testStandardBlowfishTestVector(): void
    {
        $cipher = (new BlowfishEcb(str_repeat("\x00", 8)))->encrypt(str_repeat("\x00", 8));

        self::assertSame('4ef997456198dd78', bin2hex($cipher));
    }

    /** Realistic payload and a 16-character key - openssl BF-ECB reference vector. */
    public function testAxeptaReferenceVector(): void
    {
        $cipher = (new BlowfishEcb(self::KEY))->encrypt('PayID=123&Amount=4200&Currency=EUR');

        self::assertSame(
            '2b22663ce05888aa0ce2bbd7cb79032a6a28532fd045e188f61012c5c5fbff57026778f2b6f83ee9',
            bin2hex($cipher),
        );
    }

    /** A key shorter than 16 bytes, repeated, as the Axepta protocol does. */
    public function testShortKeyIsRepeated(): void
    {
        $cipher = (new BlowfishEcb('short'))->encrypt('ABCDEFGH');

        self::assertSame('d5322f2d20ef1e90', bin2hex($cipher));
    }

    /** A key already repeated by hand must give the same result as the short key. */
    public function testRepeatedKeyMatchesShortKey(): void
    {
        $short = (new BlowfishEcb('short'))->encrypt('ABCDEFGH');
        $repeated = (new BlowfishEcb('shortshortshortshort'))->encrypt('ABCDEFGH');

        self::assertSame(bin2hex($short), bin2hex($repeated));
    }

    public function testRoundTripRestoresPayload(): void
    {
        $blowfish = new BlowfishEcb(self::KEY);
        $payload = 'PayID=&TransID=12345&Amount=4200&Currency=EUR&MAC=abcdef&RefNr=000000000123';

        self::assertSame($payload, $blowfish->decrypt($blowfish->encrypt($payload)));
    }

    /**
     * Regression guard: BNP pads plaintext with spaces where our encryption adds null bytes.
     * Without this cleanup the last value of the payload, the MAC, drags `0x20` along and no
     * notification is ever authenticated.
     */
    public function testDecryptStripsTrailingSpacePadding(): void
    {
        $blowfish = new BlowfishEcb(self::KEY);
        $cipher = $blowfish->encrypt('MAC=abcd        ');

        self::assertSame('MAC=abcd', $blowfish->decrypt($cipher));
    }

    public function testDecryptStripsTrailingNullPadding(): void
    {
        $blowfish = new BlowfishEcb(self::KEY);

        self::assertSame('Status=OK', $blowfish->decrypt($blowfish->encrypt('Status=OK')));
    }

    /** The payload goes out in ISO-8859-1: encryption must stay byte for byte. */
    public function testEncryptsBinaryPayloadUnchanged(): void
    {
        $blowfish = new BlowfishEcb(self::KEY);
        $payload = (string) mb_convert_encoding("OrderDesc=Commande à l'unité", 'ISO-8859-1', 'UTF-8');

        self::assertSame($payload, $blowfish->decrypt($blowfish->encrypt($payload)));
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BlowfishEcb('');
    }

    public function testTruncatedCiphertextIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BlowfishEcb(self::KEY))->decrypt('1234567');
    }
}
