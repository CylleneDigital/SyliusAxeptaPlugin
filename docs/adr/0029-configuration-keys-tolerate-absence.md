# 0029. Gateway configuration keys tolerate their own absence

- Status: accepted
- Date: 2026-10-20

## Context

Sylius stores a gateway configuration as written by the version of the plugin that saved it. A key
added by a later version is absent from the payment methods created before it, until someone saves
them again.

## Decision

A configuration key added after a version has a default, applied when the stored configuration does
not carry it. Its absence is silent: it neither fails the payment nor logs anything.

## Consequences

- Upgrading the plugin needs no data migration of the gateway configurations.
- A new key's default must be the behaviour shops had before it existed.
- The required keys, merchant identifier and both keys, are not concerned: without them no payment
  can be built.

## Related

- [0026. Keys are never redisplayed, survive a blank save, and one pair is active at a time](0026-keys-never-redisplayed-one-pair.md)
- [0028. Test mode only forces the demonstration description, and the page language follows the order unless forced](0028-test-mode-and-language.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
