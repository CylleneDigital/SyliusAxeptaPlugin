<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The bank sends the browser back sometimes over GET, sometimes over POST, depending on the 3DS
 * journey - its documentation explicitly asks to handle both.
 *
 * The plugin therefore targets Sylius' after-payment route for its three return URLs. This test
 * freezes that contract with the framework: were a Sylius version to restrict the route to GET, the
 * symptom would be a 405 after a successful payment, on a fraction of orders only.
 */
final class ReturnRouteTest extends KernelTestCase
{
    #[DataProvider('returnRoutes')]
    public function testTheReturnRouteAcceptsBothMethods(string $route): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        $definition = $router->getRouteCollection()->get($route);
        self::assertNotNull($definition, \sprintf('Route "%s" no longer exists.', $route));

        $methods = $definition->getMethods();

        self::assertTrue(
            [] === $methods || (\in_array('GET', $methods, true) && \in_array('POST', $methods, true)),
            \sprintf('Route "%s" must accept GET and POST, it accepts: %s.', $route, implode(', ', $methods)),
        );
    }

    /**
     * The pages the return URLs used to point at before the fix. They remain the customer's
     * **final** destination - but reached through an internal redirect, never by the bank.
     */
    #[DataProvider('finalPages')]
    public function testTheFinalPagesStillCannotReceiveThePost(string $route): void
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        \assert($router instanceof RouterInterface);

        $definition = $router->getRouteCollection()->get($route);
        self::assertNotNull($definition);

        self::assertNotContains(
            'POST',
            $definition->getMethods(),
            \sprintf(
                'Route "%s" now accepts POST. If Sylius has changed, this test may go - but ' .
                'targeting the after-payment route stays preferable: only it resolves the ' .
                'destination from the real state of the payment.',
                $route,
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function returnRoutes(): iterable
    {
        yield 'shop after-payment' => ['sylius_shop_order_after_pay'];
        yield 'capture Payum' => ['payum_capture_do'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function finalPages(): iterable
    {
        yield 'thank-you page' => ['sylius_shop_order_thank_you'];
        yield 'order page' => ['sylius_shop_order_show'];
    }
}
