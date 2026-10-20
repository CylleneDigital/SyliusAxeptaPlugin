# 0034. Every exception the protocol layer throws implements a shared marker and keeps its standard parent

- Status: accepted
- Date: 2026-10-20

## Context

The protocol layer can be used on its own. Its callers need to catch what comes from it without
enumerating classes, and without losing the catches they already wrote against the standard
exceptions it threw before having classes of its own.

## Decision

Every exception the protocol layer throws itself implements the `AxeptaException` marker, and
extends a standard SPL exception, the one it replaced where it replaced one. Errors raised by PHP
itself, such as a type error or a failing entropy source, are outside that promise, as is whatever a
substituted implementation of a protocol interface throws.

## Consequences

- A caller catches the marker to handle anything from the protocol layer, or keeps catching the
  standard parent.
- A minor version may add exception classes, or narrow a thrown type to a subclass of the former
  one, never widen it.

## Related

- [0006. The protocol layer depends on neither Sylius nor Payum, and its services take the credentials per call](0006-protocol-layer-independent-credentials-per-call.md)
- [0030. Secrets stay out of logs, dumps, traces and serialisation](0030-secrets-out-of-logs-dumps-traces.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
