<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Unit\Provider;

use CylleneDigital\SyliusAxeptaPlugin\Provider\ReturnUrlProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

final class ReturnUrlProviderTest extends TestCase
{
    /** The application secret signs more than this URL: it must not show in a dump of a capture action. */
    public function testTheSecretAppearsInNoRepresentationOfTheObject(): void
    {
        $provider = new ReturnUrlProvider($this->createStub(UrlGeneratorInterface::class), 'APP-SECRET-SHOWN');

        $representations = [
            'dump' => (new CliDumper())->dump((new VarCloner())->cloneVar($provider), true),
            'print_r' => print_r($provider, true),
            'var_export' => var_export($provider, true),
            'array cast' => print_r((array) $provider, true),
        ];

        foreach ($representations as $name => $representation) {
            self::assertStringNotContainsString('SHOWN', (string) $representation, \sprintf('The secret appears through %s.', $name));
        }
    }

    public function testASignatureMadeWithTheSecretIsValid(): void
    {
        $provider = new ReturnUrlProvider($this->createStub(UrlGeneratorInterface::class), 'app-secret');

        $signature = substr(hash_hmac('sha256', 'cyllene_digital_sylius_axepta_shop_return:42', 'app-secret'), 0, 32);

        self::assertTrue($provider->isSignatureValid('42', $signature));
        self::assertFalse($provider->isSignatureValid('43', $signature));
    }
}
