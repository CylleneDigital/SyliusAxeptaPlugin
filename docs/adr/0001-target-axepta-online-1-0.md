# 0001. The plugin targets Axepta Online 1.0 only

- Status: accepted
- Date: 2026-10-20

## Context

The bank offers two generations of its online gateway. Online 1.0 receives a signed,
Blowfish-encrypted form posted to `.aspx` endpoints and, on request, notifies the shop with a
payload encrypted the same way. Online 2.0 is not a later version of that protocol but a separate
product: a REST API with OAuth 2.0 authentication, its own messages and its own notifications.
Covering both would mean two protocol layers sharing little more than the bank's name.

## Decision

The plugin implements Axepta Online 1.0, the generation with `.aspx` endpoints, and nothing of
Online 2.0.

## Consequences

- One protocol, one cryptography, one notification format: the protocol layer stays small enough to
  be read against the bank's documentation.
- A merchant whose contract is on Online 2.0 cannot use the plugin.
- Supporting Online 2.0 would be a separate piece of work, not an extension of the existing classes.
- The plugin follows the life of Online 1.0 on the bank side: an end of support announced by the
  bank would end the plugin's usefulness.

## Related

- [0002. The plugin posts to the card form, not the payment page offering alternative payment means](0002-card-form-not-multi-means-page.md)
- [0003. One-off card payments with the bank's automatic capture](0003-one-off-card-payments-automatic-capture.md)
- [0005. Every protocol rule rests on the bank's documentation, an official vector or an observed behaviour](0005-protocol-rules-rest-on-evidence.md)
