# 0021. The plugin changes Sylius behaviour only for its own gateway

- Status: accepted
- Date: 2026-10-20

## Context

A shop rarely runs a single gateway. Some of the Sylius extension points the plugin needs are
shared by every gateway: the response to a notification, the provider that finds the notified
payment, the route a customer is sent to after a Payum capture. Changing them globally would alter
payments the plugin has nothing to do with.

## Decision

Every change applies to the `axepta` gateway only:

- the Payum actions, including the resolution of the next route after a payment, are registered on
  the `axepta` factory alone;
- the notification response is decorated, and the decoration hands every other gateway's request
  back to the response Sylius would have given;
- the notify payment provider claims the requests of Axepta payment methods only, and claims all of
  them, usable or not, so that none falls through to a generic error.

## Consequences

- Other gateways and plugins installed in the same shop keep their behaviour.
- A provider of the host application meant to handle some notifications of an Axepta payment
  method needs a higher priority than the plugin's.

## Related

- [0012. A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404](0012-notification-answers-2xx-deliberate-404.md)
- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0020. The PaymentRequest path is notified on the payment method's URL](0020-payment-request-notified-on-method-url.md)
- [0022. The customer returns through the plugin's own signed route, which decides nothing](0022-own-signed-return-route.md)
