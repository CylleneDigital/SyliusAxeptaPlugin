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

    public function testTheProtocolLayerThrowsNoBareException(): void
    {
        $offenders = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 4) . '/src/Axepta'));

        /** @var \SplFileInfo $file */
        foreach ($directory as $file) {
            if ('php' === $file->getExtension() && 1 === preg_match('/throw new \\\\/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'The protocol layer throws an exception that does not implement AxeptaException.');
    }

    /** Code written against an earlier version catches `\InvalidArgumentException`: it still does. */
    public function testTheNewExceptionsKeepTheirFormerType(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, InvalidCipherInputException::emptyKey());
        self::assertInstanceOf(\InvalidArgumentException::class, UnusablePaymentPageUrlException::containsAmpersand('successUrl'));
    }
}
