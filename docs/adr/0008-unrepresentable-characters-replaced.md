# 0008. Characters ISO-8859-1 cannot hold are replaced, not transliterated

- Status: accepted
- Date: 2026-10-20

## Context

The bank imposes ISO-8859-1 on the payment request, while Sylius works in UTF-8. Most accented
letters of western languages exist in both, but common characters do not: the euro sign, the
typographic apostrophe, long dashes, some ligatures. They mostly appear in the order description,
the one field carrying free text. The length announced to the bank, and with it the acceptance of
the request, depends on the bytes actually sent.

## Decision

The payload is converted from UTF-8 to ISO-8859-1 before encryption, and the length announced to the
bank counts the converted bytes. A character the target encoding cannot represent becomes a question
mark, whatever the server's mbstring setting - a substitute that could carry the payload separator
is not left to configuration. The plugin neither transliterates it nor rejects the payment.

## Consequences

- An unusual character never blocks a payment: the request stays valid.
- The description the customer sees on the bank's page, and that the bank archives, can carry a
  question mark where the shop wrote a euro sign or a typographic apostrophe.
- Transliteration is language-dependent and a matter of taste; an integrator who cares does it
  before the description reaches the plugin, through the Sylius payment description.

## Related

- [0009. Payload separators are neutralised in the description and refused in URLs](0009-payload-separators-neutralised.md)
