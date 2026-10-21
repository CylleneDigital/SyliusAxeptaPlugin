# 0006. The protocol layer depends on neither Sylius nor Payum, and its services take the credentials per call

- Status: accepted
- Date: 2026-10-20

## Context

Building a payment request and verifying a notification are pure protocol work: fields, encoding,
encryption, signature. Mixing them with Sylius models or Payum requests would tie the
cryptographic code to two frameworks that change on their own schedule, and make it impossible to
read or reuse without them. The plugin also serves two Sylius payment paths, which need exactly the
same protocol.

The credentials, on the other hand, are not a property of the application: a shop can have several
Axepta payment methods, each with its own merchant identifier and keys, entered in the back office.

## Decision

The protocol layer depends on neither Sylius nor Payum: it takes plain values and plain arrays, and
returns plain objects. Its builder and verifier are shared services, and take the credentials as an
argument of each call rather than through their constructor. A single provider derives those
credentials from a payment method's gateway configuration, for both payment paths.

## Consequences

- The protocol layer can be used on its own, outside Sylius, and is part of the public contract.
- One builder and one verifier serve every payment method of the shop.
- The Payum and `PaymentRequest` adapters only translate Sylius objects into protocol values and
  back; a protocol fix reaches both paths at once.
- The credentials travel with every call, which is why they are an object that hides its keys.

## Related

- [0007. Blowfish-ECB is pure PHP, substitutable through a factory](0007-blowfish-pure-php-substitutable.md)
- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0030. Secrets stay out of logs, dumps, traces and serialisation](0030-secrets-out-of-logs-dumps-traces.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
- [0034. Every exception the protocol layer throws implements a shared marker and keeps its standard parent](0034-protocol-exceptions-share-a-marker.md)
