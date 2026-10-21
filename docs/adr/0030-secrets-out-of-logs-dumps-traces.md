# 0030. Secrets stay out of logs, dumps, traces and serialisation

- Status: accepted
- Date: 2026-10-20

## Context

The HMAC and Blowfish keys sign and encrypt every payment. A private property does not hide them:
a dump prints it, serialisation writes it, and an exception trace carries the arguments of each
call. The encrypted payload and the signature are derived from the keys, and do not belong in a
log either.

## Decision

The credentials hold their keys in a form PHP itself never displays and refuses to serialise. The
parameters that receive a key or the configuration holding it are marked sensitive, so that traces
mask them. No log entry and no exception message carries a key, the payload or the signature.

## Consequences

- The credentials object cannot be serialised: code that needs them later keeps the gateway
  configuration and builds them again.
- The signer built from the credentials holds the key the same way; the cipher keeps only the key
  schedule derived from it.
- Frames of third-party code outside the plugin, through which the gateway configuration passes,
  can still carry it in a trace. In production, PHP's setting that drops arguments from exception
  traces, `zend.exception_ignore_args`, should stay on.

## Related

- [0006. The protocol layer depends on neither Sylius nor Payum, and its services take the credentials per call](0006-protocol-layer-independent-credentials-per-call.md)
- [0015. A notification is authenticated on its encrypted payload alone, against the configured merchant identifier](0015-authenticated-on-encrypted-payload-configured-merchant.md)
- [0018. The plugin logs to its own channel, at a level set by what is at stake](0018-own-log-channel-level-by-stake.md)
- [0026. Keys are never redisplayed, survive a blank save, and one pair is active at a time](0026-keys-never-redisplayed-one-pair.md)
