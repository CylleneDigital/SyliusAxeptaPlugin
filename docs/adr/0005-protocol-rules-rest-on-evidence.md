# 0005. Every protocol rule rests on the bank's documentation, an official vector or an observed behaviour

- Status: accepted
- Date: 2026-10-20

## Context

A payment protocol is easy to get almost right. The bank's documentation leaves gaps, and the
platform departs from it in places: what it sends back is padded differently, a field comes back
under another name, the signature in another case. Filling those gaps from memory, from another
implementation or from an assumption produces code that works on most orders and fails on some,
in production, where the failure is hardest to diagnose.

## Decision

Every protocol rule the plugin applies rests on one of three sources: the bank's public
documentation, one of its official vectors, or a behaviour observed on the platform in real
conditions. Never on another implementation, never on an assumption.

The protocol documentation tags each statement with its source: documented by the bank, observed on
the platform, or a design choice of the plugin.

## Consequences

- A protocol change has to name its source; one that cannot is not made.
- A reader can tell what the bank guarantees from what was only seen, and can therefore change
  without notice.
- Where the platform contradicts the documentation, the plugin follows the observed behaviour and
  says so.
- Observed behaviour is weaker evidence: it covers what was seen, not what the bank promises.

## Related

- [0001. The plugin targets Axepta Online 1.0 only](0001-target-axepta-online-1-0.md)
- [0007. Blowfish-ECB is pure PHP, substitutable through a factory](0007-blowfish-pure-php-substitutable.md)
- [0015. A notification is authenticated on its encrypted payload alone, against the configured merchant identifier](0015-authenticated-on-encrypted-payload-configured-merchant.md)
