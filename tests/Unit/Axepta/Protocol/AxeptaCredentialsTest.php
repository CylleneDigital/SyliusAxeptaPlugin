<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Protocol;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\AxeptaException;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\InvalidCredentialsException;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AxeptaCredentialsTest extends TestCase
{
    public function testDefaultsToTheHostedPaymentPage(): void
    {
        $credentials = new AxeptaCredentials('BNP_TEST_MERCHANT', 'hmac', 'blowfish');

        self::assertSame(AxeptaCredentials::DEFAULT_PAYMENT_PAGE_URL, $credentials->paymentPageUrl);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function incompleteConfigurations(): iterable
    {
        yield 'without merchant identifier' => ['', 'hmac', 'blowfish', 'merchant_id'];
        yield 'without HMAC key' => ['BNP_TEST_MERCHANT', '', 'blowfish', 'hmac_key'];
        yield 'without Blowfish key' => ['BNP_TEST_MERCHANT', 'hmac', '', 'blowfish_key'];
    }

    #[DataProvider('incompleteConfigurations')]
    public function testRejectsIncompleteConfigurationAtConstruction(
        string $merchantId,
        string $hmacKey,
        string $blowfishKey,
        string $expectedField,
    ): void {
        $this->expectException(InvalidCredentialsException::class);
        $this->expectExceptionMessage($expectedField);

        new AxeptaCredentials($merchantId, $hmacKey, $blowfishKey);
    }

    public function testRejectsEmptyPaymentPageUrl(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        new AxeptaCredentials('BNP_TEST_MERCHANT', 'hmac', 'blowfish', '');
    }

    public function testExceptionIsCatchableAsAnAxeptaException(): void
    {
        $this->expectException(AxeptaException::class);

        new AxeptaCredentials('', 'hmac', 'blowfish');
    }

    /**
     * Permanent guard: neither the keys nor a digest of them may leave the object. A future pull
     * request adding `__toString()` or `__debugInfo()` "just for debugging" would break this test -
     * which is exactly its job.
     */
    public function testDoesNotExposeItsSecrets(): void
    {
        $reflection = new \ReflectionClass(AxeptaCredentials::class);

        self::assertFalse($reflection->hasMethod('__toString'));
        self::assertFalse($reflection->hasMethod('__debugInfo'));
        self::assertFalse($reflection->hasMethod('__serialize'));

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            self::assertNotContains(
                $property->getName(),
                ['hmacKey', 'blowfishKey'],
                'The keys must never be public properties.',
            );
        }
    }

    public function testMarksItsKeysAsSensitiveParameters(): void
    {
        $constructor = (new \ReflectionClass(AxeptaCredentials::class))->getConstructor();
        self::assertNotNull($constructor);

        $sensitive = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ([] !== $parameter->getAttributes(\SensitiveParameter::class)) {
                $sensitive[] = $parameter->getName();
            }
        }

        self::assertSame(['hmacKey', 'blowfishKey'], $sensitive);
    }
}
