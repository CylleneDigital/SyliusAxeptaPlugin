# 0003. One-off card payments with the bank's automatic capture

- Status: accepted
- Date: 2026-10-20

## Context

Beyond the hosted page, the bank offers deferred capture, cancellation, refund, tokenisation and
instalments, all through its server-to-server API: calling the bank, handling its answers, keeping
more state in the shop. The hosted page alone covers the common case, an order paid once by card.
That page takes the amount twice, in the smallest unit inside the encrypted payload and in major
units on a visible field. The notification reports `OK` or `AUTHORIZED` for an accepted card, and
the bank's documentation does not say how the two differ.

## Decision

The plugin handles one-off card payments through the hosted page and never calls the bank's
server-to-server API. It writes the visible amount in major units with two decimals, without
checking the channel's currency, so only two-decimal currencies are supported. It sends no capture
mode, leaving the bank's default automatic capture, and settles a payment on `OK` and `AUTHORIZED`
alike: `AUTHORIZED` counts as paid.

## Consequences

- Refund, cancellation, deferred capture, tokenisation and instalments are done in the bank's back
  office, not from Sylius.
- With a currency of another precision, the visible amount would not match the encrypted one;
  keeping the channel to a two-decimal currency is up to the shop.
- A contract set to manual capture would mark orders paid on authorisation, before any capture: the
  merchant keeps the contract on automatic capture.

## Related

- [0001. The plugin targets Axepta Online 1.0 only](0001-target-axepta-online-1-0.md)
- [0002. The plugin posts to the card form, not the payment page offering alternative payment means](0002-card-form-not-multi-means-page.md)
- [0004. The plugin owns no data and no shop policy](0004-no-data-no-shop-policy.md)
- [0011. Only the server-to-server notification changes a payment, and the bank is never queried](0011-notification-only-source-of-truth.md)
- [0014. An unknown status changes nothing](0014-unknown-status-changes-nothing.md)
