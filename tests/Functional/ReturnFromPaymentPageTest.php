<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Functional;

use CylleneDigital\SyliusAxeptaPlugin\Provider\ReturnUrlProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Utils\PaymentScenario;

/**
 * The customer coming back from the payment page.
 *
 * Both properties that matter each cost an incident during acceptance testing: accepting POST - the
 * bank comes back sometimes over GET, sometimes over POST, depending on the 3-D Secure journey -
 * and staying **idempotent**, a second return having been observed three seconds after the first.
 */
final class ReturnFromPaymentPageTest extends WebTestCase
{
    private KernelBrowser $client;

    private PaymentScenario $scenario;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $this->scenario = new PaymentScenario($entityManager, self::getContainer());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'POST' => ['POST'];
    }

    #[DataProvider('methods')]
    public function testASettledPaymentSendsTheCustomerToTheThankYouPage(string $method): void
    {
        $this->client->request($method, $this->returnPathFor(PaymentInterface::STATE_COMPLETED));

        self::assertResponseRedirects();
        self::assertStringContainsString('/order/thank-you', $this->redirectionTarget());
    }

    #[DataProvider('methods')]
    public function testARefusedPaymentSendsTheCustomerBackToTheOrder(string $method): void
    {
        [$path, $tokenValue] = $this->returnPathAndToken(PaymentInterface::STATE_FAILED);

        $this->client->request($method, $path);

        self::assertResponseRedirects();
        self::assertStringContainsString('/order/' . $tokenValue, $this->redirectionTarget());
    }

    /**
     * The URL travels to a third party whose documentation does not state which characters are
     * accepted: we stick to the smallest possible alphabet, and the order token is not in it.
     */
    public function testTheReturnUrlOnlyUsesASafeAlphabet(): void
    {
        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.provider.return_url');
        \assert($provider instanceof ReturnUrlProvider);

        $paymentMethod = $this->scenario->createPaymentMethod();
        $payment = $this->scenario->createPayment($paymentMethod);
        $this->scenario->flush();

        $url = $provider->forPayment($payment);

        self::assertMatchesRegularExpression('#/axepta/return/\d+/[0-9a-f]{32}$#', $url);
    }

    public function testATamperedSignatureIsRejected(): void
    {
        [$path] = $this->returnPathAndToken(PaymentInterface::STATE_COMPLETED);

        // The last character is flipped to a value necessarily different from its own: forcing it
        // to zero left the signature intact one time in sixteen, depending on the payment
        // identifier - a test failing at the whim of the data is worse than no test at all.
        $tampered = substr($path, 0, -1) . ('0' === substr($path, -1) ? '1' : '0');

        $this->client->request('GET', $tampered);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The Payum token of Sylius' after-payment route is single-use: a second return stranded the
     * customer on an error, after paying. This route must be replayable indefinitely.
     */
    public function testTheReturnCanBeReplayed(): void
    {
        $path = $this->returnPathFor(PaymentInterface::STATE_COMPLETED);

        $this->client->request('POST', $path);
        $first = $this->redirectionTarget();

        $this->client->request('GET', $path);

        self::assertResponseRedirects();
        self::assertSame($first, $this->redirectionTarget());
    }

    /** An unknown payment must not produce a server error. */
    public function testAnUnknownPaymentIsNotFound(): void
    {
        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.provider.return_url');
        \assert($provider instanceof ReturnUrlProvider);

        $paymentMethod = $this->scenario->createPaymentMethod();
        $payment = $this->scenario->createPayment($paymentMethod);
        $this->scenario->flush();

        // Valid signature, non-existent payment: a 404 is what we want, not a server error.
        $path = parse_url($provider->forPayment($payment), \PHP_URL_PATH);
        \assert(\is_string($path));

        $paymentId = $payment->getId();
        \assert(\is_int($paymentId));

        $this->client->request('GET', str_replace('/' . $paymentId . '/', '/999999/', $path));

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    private function returnPathFor(string $state): string
    {
        return $this->returnPathAndToken($state)[0];
    }

    /** @return array{string, string} the return path, and the order token */
    private function returnPathAndToken(string $state): array
    {
        $provider = self::getContainer()->get('cyllene_digital_sylius_axepta.provider.return_url');
        \assert($provider instanceof ReturnUrlProvider);

        $paymentMethod = $this->scenario->createPaymentMethod();
        $payment = $this->scenario->createPayment($paymentMethod);
        $payment->setState($state);
        $this->scenario->flush();

        $order = $payment->getOrder();
        \assert(null !== $order);

        $path = parse_url($provider->forPayment($payment), \PHP_URL_PATH);
        \assert(\is_string($path));

        return [$path, (string) $order->getTokenValue()];
    }

    private function redirectionTarget(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }
}
