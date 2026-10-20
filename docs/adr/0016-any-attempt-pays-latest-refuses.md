# 0016. Any attempt can pay a payment, only the latest can refuse it

- Status: accepted
- Date: 2026-10-20

## Context

On the Payum path, Sylius resets the details of a payment still new on every capture. Reopening the
payment page, from a second tab or by going back, therefore emits a new transaction identifier,
while the bank page of the earlier attempt stays payable: that is observed. The bank then notifies
with the identifier of the attempt the customer actually used. Its signature covers the transaction
identifier, not the amount nor the currency.

## Decision

On the Payum path, a paid notification of any attempt of the payment is applied: its transaction
identifier is either the latest one stored on the payment, or one the generator's inverse resolves
to this payment. A refusal only counts for the latest attempt: a refusal of a superseded one is
ignored, the customer may have paid on a newer one since. A paid notification designating another
payment is ignored and logged as an error.

The PaymentRequest path needs no such rule: Sylius reuses the capture request while it is not final,
so a payment keeps a single transaction identifier.

## Consequences

- A customer who pays on an older bank page still has the order paid.
- A late refusal of an abandoned attempt cannot fail a payment paid in the meantime.
- A genuine notification captured on one payment cannot settle another, even one with a higher
  amount.
- Recognising an earlier attempt relies on the generator's inverse, which a substituted generator
  has to supply ([0010](0010-bank-identifiers.md)).

## Related

- [0010. The transaction identifier is readable, random and invertible; the merchant reference keeps the end of the order number](0010-bank-identifiers.md)
- [0013. A double notification is a no-op: transitions are guarded, no lock is taken](0013-double-notification-no-op.md)
- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
