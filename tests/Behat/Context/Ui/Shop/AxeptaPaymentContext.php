<?php

declare(strict_types=1);

namespace Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Tests\CylleneDigital\SyliusAxeptaPlugin\Behat\Mocker\AxeptaPaymentPageMocker;
use Webmozart\Assert\Assert;

/**
 * Plays the bank from the shop's side: checks the signed form heading to the hosted payment page,
 * then replays the server-to-server notifications.
 */
final readonly class AxeptaPaymentContext implements Context
{
    /**
     * @param PaymentRequestRepositoryInterface<PaymentRequestInterface> $paymentRequestRepository
     * @param PaymentRequestFactoryInterface<PaymentRequestInterface> $paymentRequestFactory
     */
    public function __construct(
        private SharedStorageInterface $sharedStorage,
        private AxeptaPaymentPageMocker $mocker,
        private KernelInterface $kernel,
        private PaymentRequestRepositoryInterface $paymentRequestRepository,
        private EntityManagerInterface $entityManager,
        private PaymentRequestFactoryInterface $paymentRequestFactory,
        private PaymentRequestAnnouncerInterface $paymentRequestAnnouncer,
    ) {
    }

    /**
     * Triggers the capture, that is building the signed request and recording the `TransID` -
     * what the shop does right before sending the customer to the bank.
     *
     * The step is explicit rather than inferred from the checkout: what we want to exercise here is
     * the notification, not walking the checkout, which Sylius already covers.
     *
     * @Given the shop has sent the payment request to the bank
     */
    public function theShopHasSentThePaymentRequestToTheBank(): void
    {
        $order = $this->order();
        // The object the scenario holds predates the confirmation: its payment did not exist
        // yet.
        $this->entityManager->refresh($order);

        $payment = $order->getLastPayment();
        Assert::notNull($payment, 'The order should carry a payment.');

        $paymentRequest = $this->paymentRequestFactory->create($payment, $this->paymentMethod());
        $paymentRequest->setAction(PaymentRequestInterface::ACTION_CAPTURE);

        $this->paymentRequestRepository->add($paymentRequest);
        $this->paymentRequestAnnouncer->dispatchPaymentRequestCommand($paymentRequest);
    }

    /**
     * @Then the payment request should target the Axepta payment page
     */
    public function thePaymentRequestShouldTargetTheAxeptaPaymentPage(): void
    {
        Assert::same($this->captureResponseData()['url'] ?? null, AxeptaCredentials::DEFAULT_PAYMENT_PAGE_URL);
    }

    /**
     * The fields go out as such in the self-submitting form; their rendering is covered by the
     * integration tests.
     *
     * @Then it should carry a signed payload
     */
    public function itShouldCarryASignedPayload(): void
    {
        $fields = $this->captureResponseData()['fields'] ?? [];
        Assert::isArray($fields);

        foreach (['MerchantID', 'TransID', 'Amount', 'Len', 'Data'] as $field) {
            Assert::keyExists($fields, $field);
            Assert::stringNotEmpty($fields[$field]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function captureResponseData(): array
    {
        return $this->capture()->getResponseData();
    }

    /**
     * @When /^the bank notifies the shop that the payment (succeeded|was refused)$/
     */
    public function theBankNotifiesTheShop(string $outcome): void
    {
        $notification = $this->mocker->notification(
            $this->paymentMethod(),
            $this->transactionId(),
            'succeeded' === $outcome ? 'OK' : 'FAILED',
            'succeeded' === $outcome ? '00000000' : '22720040',
        );

        $this->sharedStorage->set('axepta_notification', $notification);
        $this->sendNotification($notification);
    }

    /**
     * @When the bank sends the same notification again
     */
    public function theBankSendsTheSameNotificationAgain(): void
    {
        /** @var array<string, string> $notification */
        $notification = $this->sharedStorage->get('axepta_notification');

        $this->sendNotification($notification);
    }

    /**
     * @When someone sends a notification with an invalid signature
     */
    public function someoneSendsAForgedNotification(): void
    {
        $this->sendNotification($this->mocker->forgedNotification($this->paymentMethod(), $this->transactionId()));
    }

    /**
     * @Then the shop should have answered the bank without an error
     */
    public function theShopShouldHaveAnsweredWithoutError(): void
    {
        $status = $this->sharedStorage->get('axepta_notification_status');
        Assert::integer($status);
        Assert::lessThan($status, 400, \sprintf(
            'The shop answered %s: above 400, the bank replays 8 times over ~21 h 36.',
            $status,
        ));
    }

    /**
     * @Then my order should be paid
     */
    public function myOrderShouldBePaid(): void
    {
        Assert::same($this->reloadPaymentState(), OrderPaymentStates::STATE_PAID);
    }

    /**
     * @Then my order should not be paid
     */
    public function myOrderShouldNotBePaid(): void
    {
        Assert::notSame($this->reloadPaymentState(), OrderPaymentStates::STATE_PAID);
    }

    /**
     * @Then I should be able to pay for my order again
     */
    public function iShouldBeAbleToPayForMyOrderAgain(): void
    {
        Assert::notSame(
            $this->reloadPaymentState(),
            OrderPaymentStates::STATE_PAID,
            'An already paid order must not be payable again.',
        );
    }

    /**
     * @param array<string, string> $notification
     */
    private function sendNotification(array $notification): void
    {
        $response = $this->kernel->handle(Request::create(
            '/payment-methods/' . $this->paymentMethod()->getCode(),
            'POST',
            $notification,
        ));

        $this->sharedStorage->set('axepta_notification_status', $response->getStatusCode());
    }

    /**
     * The `TransID` emitted by the capture, as the PaymentRequest path stores it in the request's
     * response data.
     */
    private function transactionId(): string
    {
        $fields = $this->capture()->getResponseData()['fields'] ?? [];
        Assert::isArray($fields);

        $transactionId = $fields['TransID'] ?? '';

        return \is_scalar($transactionId) ? (string) $transactionId : '';
    }

    private function capture(): PaymentRequestInterface
    {
        $capture = $this->paymentRequestRepository->findOneBy([
            'payment' => $this->order()->getLastPayment(),
            'action' => PaymentRequestInterface::ACTION_CAPTURE,
        ]);
        Assert::isInstanceOf($capture, PaymentRequestInterface::class, 'No Axepta capture for this order.');

        return $capture;
    }

    /**
     * The notification is handled in another kernel: neither the in-memory object nor the
     * scenario's entity manager knows anything about it, and the entity may even have been detached
     * there. Only the database counts.
     */
    private function reloadPaymentState(): string
    {
        $state = $this->entityManager->getConnection()->fetchOne(
            'SELECT payment_state FROM sylius_order WHERE token_value = :token',
            ['token' => (string) $this->order()->getTokenValue()],
        );

        return \is_string($state) ? $state : '';
    }

    private function paymentMethod(): PaymentMethodInterface
    {
        $paymentMethod = $this->sharedStorage->get('payment_method');
        \assert($paymentMethod instanceof PaymentMethodInterface);

        return $paymentMethod;
    }

    private function order(): OrderInterface
    {
        $order = $this->sharedStorage->get('order');
        \assert($order instanceof OrderInterface);

        return $order;
    }
}
