<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Integration;

use CylleneDigital\SyliusAxeptaPlugin\Form\Type\AxeptaGatewayConfigurationType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class AxeptaGatewayConfigurationTypeTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fields(): iterable
    {
        yield 'merchant_id' => ['merchant_id'];
        yield 'hmac_key' => ['hmac_key'];
        yield 'blowfish_key' => ['blowfish_key'];
        yield 'test_mode' => ['test_mode'];
        yield 'language' => ['language'];
    }

    #[DataProvider('fields')]
    public function testExposesTheField(string $field): void
    {
        self::assertTrue($this->form()->has($field));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretFields(): iterable
    {
        yield 'hmac_key' => ['hmac_key'];
        yield 'blowfish_key' => ['blowfish_key'];
    }

    #[DataProvider('secretFields')]
    public function testSecretsAreNeverRenderedInClearText(string $field): void
    {
        $form = $this->form([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 's3cr3t-hmac-key-for-axepta-tests',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]);

        self::assertInstanceOf(PasswordType::class, $form->get($field)->getConfig()->getType()->getInnerType());

        // Keys left blank, another field failing validation: the form is rendered again, with the
        // stored keys put back into its data by the listener.
        $form->submit(['merchant_id' => '', 'hmac_key' => '', 'blowfish_key' => '']);

        self::assertFalse($form->isValid());
        self::assertSame('', $form->createView()[$field]->vars['value'], \sprintf(
            'Field "%s" renders a stored key in the page.',
            $field,
        ));
    }

    /** A key just typed is not rendered back either when the submission fails. */
    #[DataProvider('secretFields')]
    public function testATypedSecretIsNotRenderedBackWhenValidationFails(string $field): void
    {
        $form = $this->form();
        $form->submit(['merchant_id' => '', 'hmac_key' => 'typed-hmac-key', 'blowfish_key' => 'typedBlowfish16b']);

        self::assertFalse($form->isValid());
        self::assertSame('', $form->createView()[$field]->vars['value']);
    }

    /**
     * Regression risk number one when migrating.
     *
     * `PasswordType` never redisplays its value. Both key fields therefore arrive blank when
     * editing an existing method, and without protection a plain "Save" would replace them with
     * empty strings. Payments would then fail with no visible error, the signature being computed
     * with an empty key.
     */
    #[DataProvider('secretFields')]
    public function testAnEmptySubmissionDoesNotWipeAnExistingSecret(string $field): void
    {
        $existing = [
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 's3cr3t-hmac-key-for-axepta-tests',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ];

        $form = $this->form($existing);
        $form->submit([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            // What the browser sends back when the administrator does not retype the keys.
            'hmac_key' => '',
            'blowfish_key' => '',
        ]);

        /** @var array<string, mixed> $data */
        $data = $form->getData();

        self::assertSame($existing[$field], $data[$field], \sprintf(
            'Key "%s" was overwritten with an empty string.',
            $field,
        ));
    }

    /** A key that really was changed must, for its part, be taken into account. */
    public function testASubmittedSecretReplacesTheExistingOne(): void
    {
        $form = $this->form([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 'ancienne-cle',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]);

        $form->submit([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 'new-key-after-rotation',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]);

        /** @var array<string, mixed> $data */
        $data = $form->getData();

        self::assertSame('new-key-after-rotation', $data['hmac_key']);
    }

    public function testAcceptsACompleteConfiguration(): void
    {
        $form = $this->form();
        $form->submit([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 's3cr3t-hmac-key-for-axepta-tests',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
            'test_mode' => '1',
            'language' => 'fr',
        ]);

        self::assertTrue($form->isSynchronized());

        /** @var array<string, mixed> $data */
        $data = $form->getData();
        self::assertSame('BNP_TEST_MERCHANT', $data['merchant_id']);
        self::assertTrue($data['test_mode']);
        self::assertSame('fr', $data['language']);
    }

    /** Both settings are optional: an inherited configuration has neither. */
    public function testAcceptsAConfigurationWithoutTheOptionalSettings(): void
    {
        $form = $this->form();
        $form->submit([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 's3cr3t-hmac-key-for-axepta-tests',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
        ]);

        self::assertTrue($form->isSynchronized());

        /** @var array<string, mixed> $data */
        $data = $form->getData();
        self::assertFalse($data['test_mode']);
        self::assertNull($data['language']);
    }

    public function testRejectsAnUnsupportedLanguage(): void
    {
        $form = $this->form();
        $form->submit([
            'merchant_id' => 'BNP_TEST_MERCHANT',
            'hmac_key' => 's3cr3t-hmac-key-for-axepta-tests',
            'blowfish_key' => 'aB3dEf9hJk2mNp5q',
            'language' => 'pl',
        ]);

        self::assertFalse($form->get('language')->isValid());
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function form(?array $data = null): FormInterface
    {
        self::bootKernel();

        $formFactory = self::getContainer()->get('form.factory');
        \assert($formFactory instanceof FormFactoryInterface);

        // No request, hence no session to hold a CSRF token: rendering the view would fail.
        return $formFactory->create(AxeptaGatewayConfigurationType::class, $data, [
            'validation_groups' => ['sylius'],
            'csrf_protection' => false,
        ]);
    }
}
