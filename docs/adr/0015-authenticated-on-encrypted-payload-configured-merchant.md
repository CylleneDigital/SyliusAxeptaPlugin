# 0015. A notification is authenticated on its encrypted payload alone, against the configured merchant identifier

- Status: accepted
- Date: 2026-10-20

## Context

The plugin asks the bank for encrypted responses: a card notification then carries in clear only
the encrypted payload and its length, as observed on every notification received. The signature
covers a handful of payload fields, not the clear parameters, which whoever posts to the
notification URL chooses freely. The bank's documentation asks to compare parameter names
case-insensitively, not to rely on their order and to tolerate unknown ones, and to sign with the
merchant identifier received, which the bank does not reliably return under its documented name.

## Decision

When an encrypted payload is present, only its fields count: clear parameters next to it are
ignored, not merged. A message without an encrypted payload string is turned away before any
verification. Field names are compared case-insensitively, in any order, and unknown fields are
kept. The signature is recomputed with the merchant identifier of the plugin's configuration, never
the received one; a received merchant identifier that differs from it rejects the message, whatever
its signature.

## Consequences

- A sender cannot slip a field of its choosing next to a genuine payload, nor into the log of a
  rejected one.
- An attacker cannot choose the merchant identifier the signature is checked against; for a genuine
  message, the received and the configured identifiers coincide.
- The log tells a foreign merchant identifier, a configuration mistake, apart from a wrong signature.
- A notification the bank would send in clear only is not applied.

## Related

- [0005. Every protocol rule rests on the bank's documentation, an official vector or an observed behaviour](0005-protocol-rules-rest-on-evidence.md)
- [0011. Only the server-to-server notification changes a payment, and the bank is never queried](0011-notification-only-source-of-truth.md)
- [0017. The work done before the signature is checked is bounded](0017-bounded-work-before-signature.md)
