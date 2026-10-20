# 0023. A standalone transition page posts to the bank and works without JavaScript

- Status: accepted
- Date: 2026-10-20

## Context

The bank's payment page is reached by posting a signed form. The customer has to go through a page
of the shop that holds that form, for an instant. Rendered in the shop layout, it would load the
whole theme for a page nobody reads, and depend on it. A form submitted by script alone leaves a
customer whose browser runs no JavaScript unable to pay.

## Decision

The transition page is a standalone document, not the shop layout. It submits the form to the bank
as soon as it loads, and shows a submit button to a browser that runs no JavaScript. It is not
indexed, and declares both colour schemes so that it follows the browser's. Its template is
overridden through the standard bundle template overriding, like any Sylius template.

## Consequences

- The same page serves both payment paths.
- A shop restyles or translates it without forking the plugin; an override must keep the button
  for browsers without JavaScript.
- The automatic submission is an inline event handler: a content security policy that forbids
  inline script stops it, nonce or not, and since the browser does run JavaScript, the button for
  browsers without it stays hidden. Such a shop overrides the template.

## Related

- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0025. The PaymentRequest capture runs inside the customer's request](0025-capture-inside-customer-request.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
