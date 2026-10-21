# 0009. Payload separators are neutralised in the description and refused in URLs

- Status: accepted
- Date: 2026-10-20

## Context

The encrypted payload is a sequence of `key=value` pairs joined by `&`, with no escaping defined by
the protocol. A value carrying an `&` splits the message: the bank reads a truncated value and a
spurious field, and reports nothing. Two kinds of value can carry one. The order description is free
text, where an `&` or an `=` is harmless to lose. The return and notification URLs are not: a
truncated URL strands the customer on an error, or loses the notification, after they have paid.

## Decision

In the order description, `&` and `=` are replaced with a space. A return or notification URL
containing an `&` is refused: building the request fails with a protocol exception rather than
sending a truncated URL. A lone `=` in a URL is let through, the platform splitting each pair on its
first one.

## Consequences

- An odd description never breaks a payment; it only loses a character on the bank's page.
- The URLs the plugin builds carry their parameters in the path, hence no `&`.
- An integrator calling the protocol layer with URLs that carry several query parameters gets an
  exception when the request is built, before any payment.
- A substituted transaction identifier or reference must not contain `&` either; it is not checked.

## Related

- [0008. Characters ISO-8859-1 cannot hold are replaced, not transliterated](0008-unrepresentable-characters-replaced.md)
- [0022. The customer returns through the plugin's own signed route, which decides nothing](0022-own-signed-return-route.md)
- [0034. Every exception the protocol layer throws implements a shared marker and keeps its standard parent](0034-protocol-exceptions-share-a-marker.md)
