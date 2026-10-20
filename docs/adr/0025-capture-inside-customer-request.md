# 0025. The PaymentRequest capture runs inside the customer's request

- Status: accepted
- Date: 2026-10-20

## Context

On the `PaymentRequest` path, a capture is a command on a Messenger bus. Sylius has no waiting page
for an off-site payment: the response the customer gets is rendered right after the command, from
what the capture stored. The capture also builds absolute return and notification URLs, which take
the host of the current request.

## Decision

The capture stores the signed form in the payment request; the transition page is rendered from it,
in the same request. The transport carrying the payment request commands must therefore stay
synchronous, Sylius' default. A capture handled outside an HTTP request is logged as an error.

## Consequences

- Handled by a worker, the capture comes too late: the customer is not redirected to the bank, and
  the URLs are built for the router's default host, which the bank cannot reach.
- Behind a reverse proxy, the trusted proxies must be configured, the forwarded host included:
  otherwise the URLs carry the internal host, and the notification never arrives.

## Related

- [0018. The plugin logs to its own channel, at a level set by what is at stake](0018-own-log-channel-level-by-stake.md)
- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0020. The PaymentRequest path is notified on the payment method's URL](0020-payment-request-notified-on-method-url.md)
- [0022. The customer returns through the plugin's own signed route, which decides nothing](0022-own-signed-return-route.md)
- [0023. A standalone transition page posts to the bank and works without JavaScript](0023-standalone-transition-page.md)
