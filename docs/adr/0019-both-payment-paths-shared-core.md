# 0019. Both Sylius payment paths, on one shared core

- Status: accepted
- Date: 2026-10-20

## Context

Sylius 2 runs two payment mechanisms side by side, Payum and `PaymentRequest`, and picks one per
payment method through the `usePayum` flag of its gateway configuration. Payum drives the default
checkout and is stable; `PaymentRequest` is the only one exposed through the API, and Sylius marks
that foundation `@experimental`. A gateway that supports only one of them leaves out either the
standard shops or the headless ones.

## Decision

The plugin supports both paths. They share one core: the protocol layer, the credentials provider,
the return URL and the transition page renderer. Each path only adapts how Sylius triggers that
core. The choice stays Sylius' own, per payment method. Payum is the recommended default;
`PaymentRequest` is the path for a headless or API checkout.

The classes of the `PaymentRequest` adapter carry `@experimental`, like the foundation they build
on.

## Consequences

- The protocol, the gateway configuration, the return route and the transition page are the same
  on both paths: switching a shop from one to the other needs no data migration, only a second
  payment method.
- The paths diverge where Sylius drives them differently: notification URL and answer, attempts,
  capture.
- Following a change of the Sylius `PaymentRequest` contract is not a break of the plugin's
  contract.
- Every Sylius-facing behaviour has to be written and kept twice, once per adapter.

## Related

- [0006. The protocol layer depends on neither Sylius nor Payum, and its services take the credentials per call](0006-protocol-layer-independent-credentials-per-call.md)
- [0020. The PaymentRequest path is notified on the payment method's URL](0020-payment-request-notified-on-method-url.md)
- [0022. The customer returns through the plugin's own signed route, which decides nothing](0022-own-signed-return-route.md)
- [0025. The PaymentRequest capture runs inside the customer's request](0025-capture-inside-customer-request.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
