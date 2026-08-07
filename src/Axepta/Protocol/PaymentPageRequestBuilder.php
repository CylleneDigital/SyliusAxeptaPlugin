<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol;

/**
 * Composes the request to post to the hosted payment page (`payssl.aspx`).
 *
 * The message splits in two: a few fields **in clear** (`MerchantID`, `Len`, `Data`) that let BNP
 * find the contract and decrypt, and everything else in a `k=v&...` payload encrypted with
 * Blowfish-ECB and carried as hexadecimal.
 */
final readonly class PaymentPageRequestBuilder
{
    public const MESSAGE_VERSION = '2.0';

    /** Value imposed by BNP's generic demonstration account. */
    public const TEST_ORDER_DESCRIPTION = 'Test:0000';

    /** Asks BNP to encrypt its responses with the same Blowfish key. */
    private const RESPONSE_MODE = 'encrypt';

    /** The platform works in ISO-8859-1: payload, `Len` and therefore `MAC` all depend on it. */
    private const PAYLOAD_ENCODING = 'ISO-8859-1';

    /** @var list<string> codes accepted by the `Language` parameter. */
    private const SUPPORTED_LANGUAGES = ['de', 'en', 'fr', 'it', 'nl', 'pt', 'es'];

    public function __construct(
        private TransactionIdGeneratorInterface $transactionIdGenerator,
        private ReferenceProviderInterface $referenceProvider,
    ) {
    }

    public function build(AxeptaCredentials $credentials, PaymentPageRequestContext $context): PaymentPageRequest
    {
        $transactionId = $context->transactionId ?? $this->transactionIdGenerator->generate($context);
        $amountInCents = (string) $context->amountInCents;

        $mac = $credentials->signer()->sign([
            '', // PayID: the platform has not assigned it yet, the separator stays.
            $transactionId,
            $credentials->merchantId,
            $amountInCents,
            $context->currency,
        ]);

        foreach (['successUrl', 'failureUrl', 'notifyUrl', 'backUrl'] as $urlField) {
            $this->assertUsableInPayload($urlField, $context->{$urlField});
        }

        $payload = $this->composePayload([
            'TransID' => $transactionId,
            'Amount' => $amountInCents,
            'Currency' => $context->currency,
            'MAC' => $mac,
            'RefNr' => $this->referenceProvider->provide($context),
            'URLSuccess' => $context->successUrl,
            'URLFailure' => $context->failureUrl,
            'URLNotify' => $context->notifyUrl,
            'URLBack' => $context->backUrl,
            'Response' => self::RESPONSE_MODE,
            'OrderDesc' => $this->orderDescription($context),
            'MsgVer' => self::MESSAGE_VERSION,
            'Language' => $this->language($context->locale),
        ]);

        return new PaymentPageRequest($credentials->paymentPageUrl, [
            'MerchantID' => $credentials->merchantId,
            'TransID' => $transactionId,
            // The amount goes out twice, in two units: in cents inside the encrypted payload, as
            // the documentation says, and in euros here - the payment page rejects cents at this
            // spot. Behaviour observed in the sandbox, absent from the specification.
            'Amount' => number_format($context->amountInCents / 100, 2, '.', ''),
            'Len' => (string) \strlen($payload),
            'Data' => bin2hex($credentials->cipher()->encrypt($payload)),
        ]);
    }

    /**
     * Assembles `k=v&...` in the expected order, omits empty fields, and converts the whole to
     * ISO-8859-1. `Len` is computed on the result: an accented `OrderDesc` does not occupy the same
     * number of bytes as in UTF-8, and a wrong length invalidates the MAC on the BNP side.
     *
     * @param array<string, string|null> $fields
     */
    private function composePayload(array $fields): string
    {
        $pairs = [];
        foreach ($fields as $name => $value) {
            if (null === $value || '' === $value) {
                continue;
            }

            $pairs[] = $name . '=' . $value;
        }

        return (string) mb_convert_encoding(implode('&', $pairs), self::PAYLOAD_ENCODING, 'UTF-8');
    }

    private function orderDescription(PaymentPageRequestContext $context): string
    {
        if ($context->testMode) {
            return self::TEST_ORDER_DESCRIPTION;
        }

        // The only field where the caller puts arbitrary text: an `&` or an `=` would split the
        // payload in two when read back. We neutralise them rather than produce a message BNP
        // would misread.
        return str_replace(['&', '='], ' ', $context->orderDescription);
    }

    /**
     * The payload is a sequence of `k=v` separated by `&`. A return URL carrying several query
     * parameters would introduce an `&` there, splitting the message in two - BNP would read a
     * truncated URL and a spurious field, without reporting anything.
     *
     * A lone `=` is harmless on the other hand: the platform splits on the first one, which was
     * verified in the sandbox with a single-parameter URL. That is the case Sylius produces, its
     * after-payment URL carrying the Payum token.
     *
     * The failure is deliberately loud: a payment leaving with a truncated return URL strands the
     * customer on an error after they have paid.
     */
    private function assertUsableInPayload(string $field, ?string $url): void
    {
        if (null !== $url && str_contains($url, '&')) {
            throw new \InvalidArgumentException(\sprintf(
                'The URL supplied for "%s" contains an "&", which would cut the Axepta payload. ' .
                'A return URL may carry only one query parameter.',
                $field,
            ));
        }
    }

    /** `fr_FR` → `fr`. An unsupported locale sends nothing: BNP applies the contract language. */
    private function language(?string $locale): ?string
    {
        if (null === $locale) {
            return null;
        }

        $language = strtolower(substr($locale, 0, 2));

        return \in_array($language, self::SUPPORTED_LANGUAGES, true) ? $language : null;
    }
}
