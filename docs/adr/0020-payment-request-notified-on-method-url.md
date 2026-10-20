# 0020. The PaymentRequest path is notified on the payment method's URL

- Status: accepted
- Date: 2026-10-20

## Context

Sylius offers two notification routes on the `PaymentRequest` path: one per payment request, one
per payment method. The first looks natural, since it designates the request concerned, but it
does not suit an off-site gateway. It answers 404 as soon as the request has reached a final state,
before any response provider is consulted, and the bank retries a 404 for about a day; a double
notification, nominal at the bank, would trigger those retries on a payment already settled. It
also replays the action of the targeted request, the capture, instead of handling a notification.

## Decision

The notification URL handed to the bank is the payment method's. Each notification creates a fresh
payment request there, under the `notify` action: there is no final state to hit, and the
notification handler is the one called.

## Consequences

- The URL does not say which payment a notification refers to: the plugin finds it from the
  transaction identifier the bank returns, through the inverse of the generator that emitted it
  ([0010](0010-bank-identifiers.md)).
- The URL is shared by every payment of the method: the payment found must belong to it.
- The Payum path keeps its own notification URL, a token specific to the payment.

## Related

- [0010. The transaction identifier is readable, random and invertible; the merchant reference keeps the end of the order number](0010-bank-identifiers.md)
- [0012. A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404](0012-notification-answers-2xx-deliberate-404.md)
- [0013. A double notification is a no-op: transitions are guarded, no lock is taken](0013-double-notification-no-op.md)
- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0021. The plugin changes Sylius behaviour only for its own gateway](0021-changes-only-its-own-gateway.md)
