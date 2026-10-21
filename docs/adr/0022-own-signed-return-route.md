# 0022. The customer returns through the plugin's own signed route, which decides nothing

- Status: accepted
- Date: 2026-10-20

## Context

The bank sends the customer back sometimes over GET, sometimes over POST, depending on the 3-D
Secure journey. The thank-you and order pages do not accept POST. Sylius' after-payment route accepts
both, but its Payum token is single-use: a retry from the bank, a refresh or a back button strands
a customer who has just paid on an error. The order token could identify the order, but it has no
business travelling to a third party, and the bank does not document which characters a return URL
may carry.

## Decision

Both paths send the customer back to a route of the plugin, accepting GET and POST. It carries the
payment identifier and a signature derived from the application secret, both in a safe alphabet,
and answers not found when the signature does not match.

The route decides nothing and changes nothing: it reads the state of the order and redirects to the
thank-you page if a payment settled, to the order page otherwise. It sits outside the shop's locale
prefix, so its URL can be generated without a request, and takes the locale from the order.

## Consequences

- Replaying the return always gives the same result.
- The notification, not the return, settles the payment: a customer back before it lands is sent
  to the order page.
- Rotating the application secret invalidates the return URLs of payments in flight.
- The route must be imported outside the locale prefix.

## Related

- [0009. Payload separators are neutralised in the description and refused in URLs](0009-payload-separators-neutralised.md)
- [0011. Only the server-to-server notification changes a payment, and the bank is never queried](0011-notification-only-source-of-truth.md)
- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0025. The PaymentRequest capture runs inside the customer's request](0025-capture-inside-customer-request.md)
