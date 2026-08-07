<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Sylius\Behat\Service\Resolver\CurrentPageResolverInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod\AxeptaCreatePageInterface;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Page\Admin\PaymentMethod\AxeptaUpdatePageInterface;
use Webmozart\Assert\Assert;

/**
 * Steps specific to the Axepta configuration. Everything else - naming, coding, saving, checking a
 * notification - comes from Sylius' own contexts.
 */
final readonly class ManagingAxeptaPaymentMethodContext implements Context
{
    public function __construct(
        private AxeptaCreatePageInterface $createPage,
        private AxeptaUpdatePageInterface $updatePage,
        private CurrentPageResolverInterface $currentPageResolver,
        private SharedStorageInterface $sharedStorage,
    ) {
    }

    /**
     * @When I configure it with merchant id :merchantId, hmac key :hmacKey and blowfish key :blowfishKey
     */
    public function iConfigureItWithCredentials(string $merchantId, string $hmacKey, string $blowfishKey): void
    {
        $page = $this->currentPage();
        Assert::isInstanceOf($page, AxeptaCreatePageInterface::class);

        $page->specifyMerchantId($merchantId);
        $page->specifyHmacKey($hmacKey);
        $page->specifyBlowfishKey($blowfishKey);
    }

    /**
     * @When I enable its test mode
     */
    public function iEnableItsTestMode(): void
    {
        $this->updatePage->enableTestMode();
    }

    /**
     * @Then this payment method should be in test mode
     */
    public function thisPaymentMethodShouldBeInTestMode(): void
    {
        Assert::true(
            $this->updatePage->isInTestMode(),
            'Test mode should be on: without it the BNP demonstration account refuses test cards.',
        );
    }

    /**
     * Regression risk number one.
     *
     * `PasswordType` never redisplays its value: both key fields are blank on screen, and that is
     * expected. What matters is that saving without retyping them **does not erase them**, which is
     * what this step checks, by reading the stored configuration back.
     *
     * @Then this payment method should still have its Axepta keys
     */
    public function thisPaymentMethodShouldStillHaveItsKeys(): void
    {
        Assert::true(
            $this->updatePage->hasKeyKept('merchant_id'),
            'The merchant identifier is empty: the configuration was not read back.',
        );

        $paymentMethod = $this->sharedStorage->get('payment_method');
        \assert($paymentMethod instanceof PaymentMethodInterface);

        $config = $paymentMethod->getGatewayConfig()?->getConfig() ?? [];

        foreach (['hmac_key', 'blowfish_key'] as $field) {
            $key = $config[$field] ?? null;

            Assert::stringNotEmpty(
                \is_string($key) ? $key : '',
                \sprintf('Key "%s" was erased by saving.', $field),
            );
        }
    }

    /**
     * @Then I should be notified that the merchant id is required
     */
    public function iShouldBeNotifiedThatTheMerchantIdIsRequired(): void
    {
        $this->assertValidationMessage('merchant ID');
    }

    /**
     * @Then I should be notified that the hmac key is required
     */
    public function iShouldBeNotifiedThatTheHmacKeyIsRequired(): void
    {
        $this->assertValidationMessage('HMAC key');
    }

    /**
     * @Then I should be notified that the blowfish key is required
     */
    public function iShouldBeNotifiedThatTheBlowfishKeyIsRequired(): void
    {
        $this->assertValidationMessage('Blowfish key');
    }

    private function assertValidationMessage(string $needle): void
    {
        $page = $this->currentPage();
        Assert::isInstanceOf($page, AxeptaCreatePageInterface::class);

        Assert::contains(
            $page->getValidationMessage('merchant_id'),
            $needle,
            \sprintf('No validation message mentions "%s".', $needle),
        );
    }

    private function currentPage(): AxeptaCreatePageInterface|AxeptaUpdatePageInterface
    {
        $page = $this->currentPageResolver->getCurrentPageWithForm([$this->createPage, $this->updatePage]);
        \assert($page instanceof AxeptaCreatePageInterface || $page instanceof AxeptaUpdatePageInterface);

        return $page;
    }
}
