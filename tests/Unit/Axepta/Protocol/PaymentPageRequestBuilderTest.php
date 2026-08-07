<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Protocol;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\HmacSigner;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaddedReferenceProvider;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestBuilder;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestContext;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentPageRequestBuilderTest extends TestCase
{
    private const MERCHANT_ID = 'BNP_TEST_MERCHANT';

    private const HMAC_KEY = 's3cr3t-hmac-key-for-axepta-tests-0123456789';

    private const BLOWFISH_KEY = 'aB3dEf9hJk2mNp5q';

    private const TRANSACTION_ID = 'sylius-42-0123456789abcdef';

    public function testPostsToTheConfiguredPaymentPage(): void
    {
        $request = $this->build();

        self::assertSame(AxeptaCredentials::DEFAULT_PAYMENT_PAGE_URL, $request->url);
        self::assertSame(self::MERCHANT_ID, $request->fields['MerchantID']);
        self::assertSame(self::TRANSACTION_ID, $request->fields['TransID']);
        self::assertSame(self::TRANSACTION_ID, $request->transactionId());
    }

    /**
     * A customer reloading the redirect page replays the capture. Without reusing the `TransID`
     * already assigned, every reload would open one more transaction at BNP for the same payment.
     */
    public function testReusesAnAlreadyAssignedTransactionId(): void
    {
        $request = $this->build(transactionId: 'sylius-42-dejaattribue');

        self::assertSame('sylius-42-dejaattribue', $request->transactionId());
        self::assertStringContainsString('TransID=sylius-42-dejaattribue', $this->payloadOf($request->fields['Data']));
    }

    /** The MAC covers the `TransID`: the signer must see the one actually sent. */
    public function testSignsTheReusedTransactionId(): void
    {
        $payload = $this->payloadOf($this->build(transactionId: 'sylius-42-dejaattribue')->fields['Data']);

        $expected = (new HmacSigner(self::HMAC_KEY))
            ->sign(['', 'sylius-42-dejaattribue', self::MERCHANT_ID, '4200', 'EUR']);

        self::assertStringContainsString('MAC=' . $expected, $payload);
    }

    /**
     * Regression guard on the doubled amount: the payment page rejects cents on the visible
     * field, whereas the encrypted payload requires them. Both units coexist on purpose.
     */
    public function testSendsTheAmountInEurosOutsideAndInCentsInside(): void
    {
        $request = $this->build();

        self::assertSame('42.00', $request->fields['Amount']);
        self::assertStringContainsString('Amount=4200', $this->payloadOf($request->fields['Data']));
    }

    public function testSignsTheRequestWithAnEmptyPayId(): void
    {
        $payload = $this->payloadOf($this->build()->fields['Data']);

        $expected = (new HmacSigner(self::HMAC_KEY))
            ->sign(['', self::TRANSACTION_ID, self::MERCHANT_ID, '4200', 'EUR']);

        self::assertStringContainsString('MAC=' . $expected, $payload);
    }

    public function testAnnouncesThePayloadLengthInBytes(): void
    {
        $request = $this->build();

        self::assertSame((string) \strlen($this->rawPayloadOf($request->fields['Data'])), $request->fields['Len']);
    }

    /**
     * Corrected non-conformance: the platform works in ISO-8859-1. An accented `OrderDesc` does
     * not occupy the same number of bytes as in UTF-8, and `Len` is part of what BNP reads back: a
     * wrong length only breaks accented orders, which is the worst possible symptom.
     *
     * The accented strings below are the test vectors themselves - do not "translate" them.
     */
    public function testEncodesThePayloadInLatin1(): void
    {
        $request = $this->build(orderDescription: "Commande à l'unité");
        $raw = $this->rawPayloadOf($request->fields['Data']);

        self::assertStringContainsString(
            (string) mb_convert_encoding("OrderDesc=Commande à l'unité", 'ISO-8859-1', 'UTF-8'),
            $raw,
        );
        self::assertSame((string) \strlen($raw), $request->fields['Len']);
        self::assertNotSame(mb_strlen("OrderDesc=Commande à l'unité"), \strlen($raw));
    }

    public function testAccentedDescriptionSurvivesARoundTrip(): void
    {
        $payload = $this->payloadOf($this->build(orderDescription: 'Article « Édition limitée »')->fields['Data']);

        self::assertStringContainsString('OrderDesc=Article « Édition limitée »', $payload);
    }

    public function testPadsTheReferenceToTwelveCharacters(): void
    {
        $payload = $this->payloadOf($this->build(reference: '000000008')->fields['Data']);

        self::assertStringContainsString('RefNr=000000000008', $payload);
    }

    public function testOmitsEmptyFields(): void
    {
        $payload = $this->payloadOf($this->build(backUrl: null, locale: null)->fields['Data']);

        self::assertStringNotContainsString('URLBack=', $payload);
        self::assertStringNotContainsString('Language=', $payload);
    }

    public function testKeepsTheDocumentedFieldOrder(): void
    {
        $payload = $this->payloadOf($this->build()->fields['Data']);

        $positions = [];
        foreach (['TransID', 'Amount', 'Currency', 'MAC', 'RefNr', 'URLSuccess', 'URLFailure', 'URLNotify', 'Response', 'OrderDesc', 'MsgVer'] as $field) {
            $position = strpos($payload, $field . '=');
            self::assertNotFalse($position, \sprintf('Field "%s" is missing from the payload.', $field));
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
    }

    public function testAnnouncesTheProtocolVersion(): void
    {
        self::assertStringContainsString('MsgVer=2.0', $this->payloadOf($this->build()->fields['Data']));
    }

    public function testAsksForEncryptedResponses(): void
    {
        self::assertStringContainsString('Response=encrypt', $this->payloadOf($this->build()->fields['Data']));
    }

    /** The generic BNP demonstration account refuses test cards without this description. */
    public function testTestModeForcesTheDemoOrderDescription(): void
    {
        $payload = $this->payloadOf($this->build(orderDescription: 'Commande 42', testMode: true)->fields['Data']);

        self::assertStringContainsString('OrderDesc=Test:0000', $payload);
        self::assertStringNotContainsString('Commande 42', $payload);
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function locales(): iterable
    {
        yield 'full Sylius locale' => ['fr_FR', 'fr'];
        yield 'code court' => ['nl', 'nl'];
        yield 'case-insensitive' => ['PT_BR', 'pt'];
        yield 'unsupported' => ['pl_PL', null];
        yield 'absente' => [null, null];
    }

    #[DataProvider('locales')]
    public function testMapsTheLocaleToASupportedLanguage(?string $locale, ?string $expected): void
    {
        $payload = $this->payloadOf($this->build(locale: $locale)->fields['Data']);

        if (null === $expected) {
            self::assertStringNotContainsString('Language=', $payload);

            return;
        }

        self::assertStringContainsString('Language=' . $expected, $payload);
    }

    /**
     * The payload is read back by splitting on `&` and `=`: a separator inside the description
     * would cut it in two. It is the only field where the caller puts arbitrary text.
     */
    public function testNeutralisesSeparatorsInTheOrderDescription(): void
    {
        $fields = $this->parse($this->build(orderDescription: 'Vin & fromage = bonheur')->fields['Data']);

        self::assertSame('Vin   fromage   bonheur', $fields['OrderDesc'] ?? null);
        self::assertArrayHasKey('MsgVer', $fields, 'The description must not swallow the fields that follow.');
    }

    /**
     * Reads the payload back the way the platform will, to check it splits cleanly.
     *
     * @return array<string, string>
     */
    private function parse(string $data): array
    {
        $fields = [];
        foreach (explode('&', $this->payloadOf($data)) as $pair) {
            [$name, $value] = explode('=', $pair, 2);
            $fields[$name] = $value;
        }

        return $fields;
    }

    private function build(
        string $orderDescription = 'Commande 000000008',
        string $reference = '000000008',
        ?string $backUrl = 'https://shop.test/order',
        ?string $locale = 'fr_FR',
        bool $testMode = false,
        ?string $transactionId = null,
        string $successUrl = 'https://shop.test/thank-you',
    ): \CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequest {
        $generator = new class() implements TransactionIdGeneratorInterface {
            public function generate(PaymentPageRequestContext $context): string
            {
                return PaymentPageRequestBuilderTest::transactionId();
            }

            public function resolvePaymentIdentifier(string $transactionId): ?string
            {
                return null;
            }
        };

        return (new PaymentPageRequestBuilder($generator, new PaddedReferenceProvider()))->build(
            $this->credentials(),
            new PaymentPageRequestContext(
                amountInCents: 4200,
                currency: 'EUR',
                paymentIdentifier: '42',
                reference: $reference,
                orderDescription: $orderDescription,
                successUrl: $successUrl,
                failureUrl: 'https://shop.test/order',
                notifyUrl: 'https://shop.test/notify',
                backUrl: $backUrl,
                locale: $locale,
                testMode: $testMode,
                transactionId: $transactionId,
            ),
        );
    }

    /**
     * Sylius' after-payment URL carries the Payum token as a query parameter. Verified in the
     * sandbox: BNP splits on the first `=`, and the value arrives whole.
     */
    public function testAReturnUrlMayCarryASingleQueryParameter(): void
    {
        $request = $this->build(successUrl: 'https://shop.test/after-pay?payum_token=abc123');

        self::assertStringContainsString(
            'URLSuccess=https://shop.test/after-pay?payum_token=abc123&',
            $this->payloadOf($request->fields['Data']),
        );
    }

    /**
     * A second parameter would introduce an `&`, the payload separator: BNP would read a
     * truncated URL and a spurious field, without reporting anything. Better to fail here than to
     * strand a customer on an error after they have paid.
     */
    public function testAReturnUrlWithTwoQueryParametersIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/successUrl/');

        $this->build(successUrl: 'https://shop.test/after-pay?payum_token=abc123&locale=fr');
    }

    public static function transactionId(): string
    {
        return self::TRANSACTION_ID;
    }

    private function credentials(): AxeptaCredentials
    {
        return new AxeptaCredentials(self::MERCHANT_ID, self::HMAC_KEY, self::BLOWFISH_KEY);
    }

    /** Decrypted payload, brought back to UTF-8 so it compares with the test's literals. */
    private function payloadOf(string $data): string
    {
        return (string) mb_convert_encoding($this->rawPayloadOf($data), 'UTF-8', 'ISO-8859-1');
    }

    /** Decrypted payload as it goes out on the wire: ISO-8859-1 bytes. */
    private function rawPayloadOf(string $data): string
    {
        return $this->credentials()->cipher()->decrypt((string) hex2bin($data));
    }
}
