<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Permanent guard against key leakage.
 *
 * These checks look at the source code and at signatures, not at behaviour: their job is to fail
 * the day a contribution adds a `dump()` "just while debugging" or a `__toString()` "for the logs".
 * That is exactly the kind of addition that slips through a review.
 */
final class SecretLeakTest extends TestCase
{
    /**
     * Classes holding a secret are **discovered**, not enumerated.
     *
     * A hand-written list only protects what someone thought to put in it: `ReturnUrlProvider`,
     * added along with the application secret, escaped one for a whole day. The criterion is the
     * same as the marking test's: a parameter whose name suggests a key.
     *
     * @return list<class-string>
     */
    private static function classesHoldingSecrets(): array
    {
        $classes = [];

        foreach (self::sourceClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if (1 === preg_match(self::SECRET_PARAMETER_PATTERN, $parameter->getName())) {
                        $classes[] = $class;

                        continue 3;
                    }
                }
            }
        }

        return $classes;
    }

    /** @return list<class-string> */
    private static function sourceClasses(): array
    {
        $classes = [];

        foreach (self::sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (1 !== preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
                continue;
            }
            if (1 !== preg_match('/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $cn)) {
                continue;
            }

            $class = $ns[1] . '\\' . $cn[1];
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private const SECRET_PARAMETER_PATTERN = '/key|secret/i';

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function secretHolders(): iterable
    {
        foreach (self::classesHoldingSecrets() as $class) {
            yield $class => [$class];
        }
    }

    /**
     * Any magic representation method is an open door: `dump()`, `json_encode()` or an exception
     * message is then enough to get the key out.
     *
     * @param class-string $class
     */
    #[DataProvider('secretHolders')]
    public function testNoClassExposesItselfThroughAMagicMethod(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        foreach (['__toString', '__debugInfo', '__serialize', '__sleep', 'jsonSerialize'] as $method) {
            self::assertFalse(
                $reflection->hasMethod($method),
                \sprintf('%s::%s() exposerait un secret.', $class, $method),
            );
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('secretHolders')]
    public function testNoSecretIsHeldInAPublicProperty(string $class): void
    {
        $publicNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        self::assertSame(
            [],
            array_filter($publicNames, static fn (string $name): bool => 1 === preg_match('/key|secret|password/i', $name)),
            \sprintf('%s exposes a secret as a public property.', $class),
        );
    }

    /**
     * Without `#[\SensitiveParameter]`, PHP writes the parameter value into every exception
     * trace, including those Monolog serialises.
     */
    public function testEverySecretParameterIsMarkedSensitive(): void
    {
        foreach (self::classesHoldingSecrets() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if (1 !== preg_match(self::SECRET_PARAMETER_PATTERN, $parameter->getName())) {
                        continue;
                    }

                    self::assertNotSame(
                        [],
                        $parameter->getAttributes(\SensitiveParameter::class),
                        \sprintf(
                            '%s::%s($%s) must carry #[\SensitiveParameter].',
                            $class,
                            $method->getName(),
                            $parameter->getName(),
                        ),
                    );
                }
            }
        }
    }

    /** A `dump()` left in a payment plugin ends up printing a key somewhere. */
    public function testNoDebugStatementSurvivedInTheShippedCode(): void
    {
        $debugFunctions = ['var_dump', 'print_r', 'dump', 'dd', 'var_export'];
        $offenders = [];

        foreach (self::sourceFiles() as $file) {
            // The analysis works on tokens, not on text: this repository's comments name those
            // functions precisely to say not to use them.
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (\is_array($token) && \T_STRING === $token[0] && \in_array($token[1], $debugFunctions, true)) {
                    $offenders[] = $file . ':' . $token[2];
                }
            }
        }

        self::assertSame([], $offenders, 'Debugging statement in shipped code.');
    }

    /**
     * The protocol's exception messages must teach nothing to whoever probes the notification
     * URL: not the key, not the payload, not what would make a message acceptable.
     */
    public function testProtocolExceptionMessagesRevealNothingUsable(): void
    {
        $offenders = [];

        foreach (self::sourceFiles('/Axepta/Exception') as $file) {
            $contents = (string) file_get_contents($file);

            if (1 === preg_match('/\$(?:hmacKey|blowfishKey|payload|data|mac)\b/i', $contents)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, 'An exception message interpolates a sensitive value.');
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(string $subPath = ''): array
    {
        $directory = \dirname(__DIR__, 2) . '/src' . $subPath;

        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
