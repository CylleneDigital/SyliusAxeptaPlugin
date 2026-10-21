# 0027. The payment page URL comes from the bundle configuration only; the merchant identifier decides the environment

- Status: accepted
- Date: 2026-10-20

## Context

The customer's card details are typed on the page the transition form posts to. The gateway
configuration lives in the database, and the back office does not show every key it may hold:
reading the endpoint from it would let whoever can write a row send the card form to a page of
their choosing, invisibly. The bank, for its part, has no separate test endpoint: test and
production share the same URL, and the merchant identifier tells them apart.

## Decision

The payment page URL comes from the bundle configuration only, with the bank's card form as
default; a URL stored in a gateway configuration is ignored. There is no test URL: the merchant
identifier decides the environment.

## Consequences

- Changing the endpoint is a change of the application's configuration, not a database write.
- The setting exists to absorb an endpoint change on the bank's side, not to switch between test
  and production.
- A production merchant identifier entered for testing charges real cards.

## Related

- [0002. The plugin posts to the card form, not the payment page offering alternative payment means](0002-card-form-not-multi-means-page.md)
- [0028. Test mode only forces the demonstration description, and the page language follows the order unless forced](0028-test-mode-and-language.md)
