# 0007. Blowfish-ECB is pure PHP, substitutable through a factory

- Status: accepted
- Date: 2026-10-20

## Context

The protocol encrypts the payload with Blowfish-ECB. OpenSSL 3 moved Blowfish to its legacy
provider, disabled by default: the OpenSSL `BF-ECB` cipher is unavailable to PHP running on OpenSSL
3 with its default configuration, and the call fails. Re-enabling the legacy provider would turn
obsolete algorithms back on for the whole process, and depend on how each server's OpenSSL is
configured.

Some shops may still have a native implementation at hand, faster or held in dedicated hardware. The
Blowfish key cannot be injected when the container is built: it is only known at runtime, and
differs per payment method.

## Decision

The plugin ships its own Blowfish-ECB implementation in pure PHP, following the public algorithm and
the protocol's conventions for key repetition and padding. Legacy algorithms are not re-enabled.

The cipher is created through `BlowfishEcbFactoryInterface`, which receives the key at runtime. An
integrator substitutes the factory to replace the pure-PHP cipher with a native one.

## Consequences

- Payments do not depend on the OpenSSL configuration of the server.
- A pure-PHP cipher is slower than a native one would be; with payloads this small, the cost is
  accepted.
- The cipher is cryptographic code maintained by the plugin; it is not to be "simplified" by wiring
  OpenSSL back in.
- A substituted cipher must honour the same conventions, including stripping trailing null bytes and
  spaces on decryption, the bank padding its messages with spaces.
- The factory and the cipher interface are part of the public contract.

## Related

- [0005. Every protocol rule rests on the bank's documentation, an official vector or an observed behaviour](0005-protocol-rules-rest-on-evidence.md)
- [0006. The protocol layer depends on neither Sylius nor Payum, and its services take the credentials per call](0006-protocol-layer-independent-credentials-per-call.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
