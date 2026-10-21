# 0017. The work done before the signature is checked is bounded

- Status: accepted
- Date: 2026-10-20

## Context

The signature of a notification sits inside its encrypted payload: it can only be checked after
decryption. The notification URL is reachable by anyone, so anyone can make the shop decrypt without
holding a key. The decryption is pure PHP, and its cost grows with the size of the payload. The
bank also announces the plaintext length next to the payload, a value nobody has authenticated yet
when it is read.

## Decision

The size of the encrypted payload is capped before it is decoded or decrypted, well above what a
genuine notification weighs: a larger one is rejected as undecipherable. The announced plaintext
length can only restore what decryption stripped, the trailing padding of the plaintext; it never
grows the payload beyond the decrypted bytes.

## Consequences

- The decryption a request on the notification URL can trigger is bounded, whatever its size: a
  flood of oversized payloads cannot exhaust the workers through the cipher. Limiting the body size
  itself stays with the server configuration.
- A forged length cannot inflate the payload, only pad it within the decrypted bytes, where the
  signature check then fails.
- A genuine notification larger than the cap would be rejected; the cap leaves ample headroom over
  what the bank sends.

## Related

- [0007. Blowfish-ECB is pure PHP, substitutable through a factory](0007-blowfish-pure-php-substitutable.md)
- [0015. A notification is authenticated on its encrypted payload alone, against the configured merchant identifier](0015-authenticated-on-encrypted-payload-configured-merchant.md)
