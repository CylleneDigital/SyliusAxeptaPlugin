# 0002. The plugin posts to the card form, not the payment page offering alternative payment means

- Status: accepted
- Date: 2026-10-20

## Context

Online 1.0 exposes two hosted pages speaking the same protocol: a card form, and a payment page that
also offers alternative payment means. Cards notify the shop over POST. The alternative payment
means notify over GET, which the plugin does not handle: a customer paying with one of them would be
charged while the order never turned paid, for want of an applied notification.

## Decision

The plugin posts to the hosted card form. A genuine notification arriving over GET is authenticated
and logged as an error, never applied.

## Consequences

- Every payment means the card form offers notifies over POST, which the plugin handles.
- The alternative payment means are out of reach until GET notifications are handled.
- The payment page URL stays configurable, to absorb an endpoint change on the bank's side; pointing
  it at the multi-means page brings back the unpaid-order case, and the documentation warns against
  it.
- An error logged for a GET notification is a sign the customer was offered more than cards.

## Related

- [0001. The plugin targets Axepta Online 1.0 only](0001-target-axepta-online-1-0.md)
- [0003. One-off card payments with the bank's automatic capture](0003-one-off-card-payments-automatic-capture.md)
- [0011. Only the server-to-server notification changes a payment, and the bank is never queried](0011-notification-only-source-of-truth.md)
- [0027. The payment page URL comes from the bundle configuration only; the merchant identifier decides the environment](0027-endpoint-from-bundle-configuration.md)
