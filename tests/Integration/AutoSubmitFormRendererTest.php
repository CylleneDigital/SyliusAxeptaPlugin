<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Integration;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\HmacSigner;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequest;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestBuilder;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\PaymentPageRequestContext;
use CylleneDigital\SyliusAxeptaPlugin\Provider\CredentialsProvider;
use CylleneDigital\SyliusAxeptaPlugin\Renderer\AutoSubmitFormRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Full chain from the container services: gateway configuration → credentials → signed request →
 * transition page. This is what the customer receives between the shop and the bank.
 */
final class AutoSubmitFormRendererTest extends KernelTestCase
{
    private const MERCHANT_ID = 'BNP_TEST_MERCHANT';

    private const HMAC_KEY = 's3cr3t-hmac-key-for-axepta-tests';

    private const BLOWFISH_KEY = 'aB3dEf9hJk2mNp5q';

    public function testPostsTheSignedFieldsToThePaymentPage(): void
    {
        $request = $this->paymentPageRequest();
        $html = $this->renderer()->render($request);

        self::assertStringContainsString(
            \sprintf('<form method="post" action="%s">', AxeptaCredentials::DEFAULT_PAYMENT_PAGE_URL),
            $html,
        );

        foreach (['MerchantID', 'TransID', 'Amount', 'Len', 'Data'] as $field) {
            self::assertStringContainsString(
                \sprintf('name="%s" value="%s"', $field, $request->fields[$field]),
                $html,
                \sprintf('Field "%s" must be posted.', $field),
            );
        }
    }

    /** The visible amount goes out in euros, the encrypted payload in cents. */
    public function testPostsTheVisibleAmountInEuros(): void
    {
        self::assertStringContainsString('name="Amount" value="42.00"', $this->renderer()->render($this->paymentPageRequest()));
    }

    /** The payload is signed with the key from the gateway configuration, not another one. */
    public function testTheEncryptedPayloadCarriesAValidSignature(): void
    {
        $request = $this->paymentPageRequest();

        $payload = $this->credentials()->cipher()->decrypt((string) hex2bin($request->fields['Data']));
        $expectedMac = (new HmacSigner(self::HMAC_KEY))->sign(
            ['', $request->transactionId(), self::MERCHANT_ID, '4200', 'EUR'],
        );

        self::assertStringContainsString('MAC=' . $expectedMac, $payload);
    }

    /** A customer without JavaScript must be able to trigger the submission themselves. */
    public function testRemainsUsableWithoutJavaScript(): void
    {
        $html = $this->renderer()->render($this->paymentPageRequest());

        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString('<button type="submit">', $html);
    }

    /** A hostile description must not be able to break out of the HTML attribute. */
    public function testEscapesFieldValues(): void
    {
        $request = new PaymentPageRequest('https://paymentpage.test/payssl.aspx', [
            'MerchantID' => '"><script>alert(1)</script>',
        ]);

        $html = $this->renderer()->render($request);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    private function paymentPageRequest(): PaymentPageRequest
    {
        self::bootKernel();

        $builder = self::getContainer()->get('cyllene_digital_sylius_axepta.protocol.payment_page_request_builder');
        \assert($builder instanceof PaymentPageRequestBuilder);

        return $builder->build($this->credentials(), new PaymentPageRequestContext(
            amountInCents: 4200,
            currency: 'EUR',
            paymentIdentifier: '42',
            reference: '000000008',
            orderDescription: 'Commande 000000008',
            successUrl: 'https://shop.test/thank-you',
            failureUrl: 'https://shop.test/order/abc',
            notifyUrl: 'https://shop.test/payment/notify/abc',
            backUrl: 'https://shop.test/order/abc',
            locale: 'fr_FR',
        ));
    }

    private function credentials(): AxeptaCredentials
    {
        self::bootKernel();

        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.provider.credentials');
        \assert($provider instanceof CredentialsProvider);

        return $provider->fromArray([
            'merchant_id' => self::MERCHANT_ID,
            'hmac_key' => self::HMAC_KEY,
            'blowfish_key' => self::BLOWFISH_KEY,
        ]);
    }

    private function renderer(): AutoSubmitFormRenderer
    {
        self::bootKernel();

        $renderer = self::getContainer()->get('cyllene_digital_sylius_axepta.renderer.auto_submit_form');
        \assert($renderer instanceof AutoSubmitFormRenderer);

        return $renderer;
    }
}
