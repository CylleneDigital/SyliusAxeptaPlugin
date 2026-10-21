# 0028. Test mode only forces the demonstration description, and the page language follows the order unless forced

- Status: accepted
- Date: 2026-10-20

## Context

The bank's generic demonstration account refuses test cards, without saying why, unless the order
description carries a fixed value. Since the merchant identifier decides the environment, a test
mode has no URL to switch. The payment page accepts a fixed list of languages, while a shop's
locales are its own. Besides the credentials, these are the only two settings of the gateway form
that change how a payment behaves, hence one record for both.

## Decision

The test mode only forces the order description the demonstration account requires. It switches
nothing else.

The payment page language is the one configured on the payment method when it is forced, otherwise
the language of the order's locale. A language the payment page does not support is not sent: the
bank then applies the language of the merchant contract.

## Consequences

- With a dedicated test merchant identifier, the test mode stays off, and real descriptions go
  through.
- A customer pays in the language they ordered in, when the bank supports it.
- Both settings are optional: a configuration that does not carry them pays with test mode off, in
  the order's language.

## Related

- [0027. The payment page URL comes from the bundle configuration only; the merchant identifier decides the environment](0027-endpoint-from-bundle-configuration.md)
- [0029. Gateway configuration keys tolerate their own absence](0029-configuration-keys-tolerate-absence.md)
