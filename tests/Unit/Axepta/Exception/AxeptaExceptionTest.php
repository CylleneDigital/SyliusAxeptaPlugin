<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Axepta\Exception;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\AxeptaException;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\InvalidCipherInputException;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Exception\UnusablePaymentPageUrlException;
use PHPUnit\Framework\TestCase;

/**
 * The marker promises that catching `AxeptaException` catches whatever the protocol layer throws.
 * These checks fail the day a contribution throws a bare SPL exception from it again.
 */
final class AxeptaExceptionTest extends TestCase
{
    public function testEveryExceptionClassCarriesTheMarker(): void
    {
        foreach (glob(\dirname(__DIR__, 4) . '/src/Axepta/Exception/*.php') ?: [] as $file) {
            $class = 'CylleneDigital\\SyliusAxeptaPlugin\\Axepta\\Exception\\' . basename($file, '.php');

            if (AxeptaException::class === $class) {
                continue;
            }

            self::assertTrue(is_subclass_of($class, AxeptaException::class), \sprintf('%s does not implement AxeptaException.', $class));
        }
    }

    /**
     * Every `throw` of the protocol layer goes through a named constructor of its own exceptions.
     * Matching that convention rather than hunting SPL class names also catches an imported class,
     * a line break before it, or a rethrow.
     */
    public function testTheProtocolLayerOnlyThrowsItsOwnExceptions(): void
    {
        $offenders = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 4) . '/src/Axepta'));

        /** @var \SplFileInfo $file */
        foreach ($directory as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            // Tokens, not text: comments of this layer use the word "throw".
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($file->getPathname())),
                static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
            ));

            foreach ($tokens as $i => $token) {
                if (!\is_array($token) || \T_THROW !== $token[0]) {
                    continue;
                }

                $class = $tokens[$i + 1] ?? null;
                $operator = $tokens[$i + 2] ?? null;

                if (!\is_array($class) || \T_STRING !== $class[0] || !str_ends_with($class[1], 'Exception') ||
                    !\is_array($operator) || \T_DOUBLE_COLON !== $operator[0]) {
                    $offenders[] = $file->getFilename() . ':' . $token[2];
                }
            }
        }

        self::assertSame([], $offenders, 'The protocol layer throws something other than its own named exceptions.');
    }

    /** Code written against an earlier version catches `\InvalidArgumentException`: it still does. */
    public function testTheNewExceptionsKeepTheirFormerType(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, InvalidCipherInputException::emptyKey());
        self::assertInstanceOf(\InvalidArgumentException::class, UnusablePaymentPageUrlException::containsAmpersand('successUrl'));
    }
}
