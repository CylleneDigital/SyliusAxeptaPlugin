# 0012. A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404

- Status: accepted
- Date: 2026-10-20

## Context

The bank's documentation states that a 404 or a 500 on the notification URL makes it retry, for
about a day. That a 2xx stops the retries is observed, not documented. An error answered for a
payment already handled, or an uncaught failure, would bring the notification back for nothing. On
the PaymentRequest path, Sylius asks a provider for the payment a notification designates, and its
contract leaves two ways out only: return a payment, or throw.

## Decision

Once a notification is tied to its payment, it is answered with a 2xx whatever happens next: a
replay, a refusal or a handling failure is logged, not surfaced. A failure outside that handling,
such as a write conflict between two simultaneous notifications on either path, can still end in a
500. On the Payum path the notification token designates the payment, so every message the action
handles gets PayumBundle's 204, authenticated or not, unless its changes fail to be written. On the
PaymentRequest path the plugin decorates Sylius' notify response provider and answers 200 for its
own gateway only.

On the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404: a message
without an encrypted payload, not authenticated, sent over GET, matching no payment of the method,
or reaching an incomplete payment method. The bank only retries what it sent, so the retries recover
a genuine notification once its cause is fixed, if that happens before the bank stops retrying,
typically a key rotated on one side with payments in flight; a forged message triggers nothing.

## Consequences

- A double notification costs no retry.
- A handling failure leaves the payment in its state, to be settled by hand or by a later
  notification.
- The deliberate 404s do not show up in an error log that excludes 404 responses: their reason is in
  the plugin's log channel.
- Other gateways of the shop keep Sylius' default answer.

## Related

- [0011. Only the server-to-server notification changes a payment, and the bank is never queried](0011-notification-only-source-of-truth.md)
- [0013. A double notification is a no-op: transitions are guarded, no lock is taken](0013-double-notification-no-op.md)
- [0018. The plugin logs to its own channel, at a level set by what is at stake](0018-own-log-channel-level-by-stake.md)
- [0020. The PaymentRequest path is notified on the payment method's URL](0020-payment-request-notified-on-method-url.md)
- [0021. The plugin changes Sylius behaviour only for its own gateway](0021-changes-only-its-own-gateway.md)
