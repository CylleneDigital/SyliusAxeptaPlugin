# 0010. The transaction identifier is readable, random and invertible; the merchant reference keeps the end of the order number

- Status: accepted
- Date: 2026-10-20

## Context

Each request carries two identifiers of the shop's making. The transaction identifier must be unique
per payment within a bounded length, since a collision would tie a notification to the wrong
payment; covered by the signature, it is the only one the bank returns reliably, so it leads a
notification back to its payment. The merchant reference has a fixed alphanumeric format, and is
what the merchant reads in the bank's back office.

## Decision

The transaction identifier is a readable prefix, the payment identifier and a random part;
shortening truncates the payment identifier, never the random part. A failing entropy source stops
the payment rather than producing a predictable identifier. The generator also provides the inverse,
from a transaction identifier back to the payment identifier: it is part of
`TransactionIdGeneratorInterface`, and substituting the generator means supplying it.

The merchant reference is the order number reduced to the required format, keeping its end when too
long, the part that tells orders apart. It comes from a substitutable `ReferenceProviderInterface`.

## Consequences

- The payment can be found from the transaction identifier at a glance during bank reconciliation.
- Two identifiers generated for the same payment differ, with no counter to maintain.
- A substituted generator whose inverse does not recognise its own identifiers leaves every
  notification unattached on the PaymentRequest path, and those of earlier attempts on the Payum
  path.
- A shop with its own reconciliation conventions substitutes the reference provider to show its own
  reference in the bank's back office.

## Related

- [0004. The plugin owns no data and no shop policy](0004-no-data-no-shop-policy.md)
- [0016. Any attempt can pay a payment, only the latest can refuse it](0016-any-attempt-pays-latest-refuses.md)
- [0020. The PaymentRequest path is notified on the payment method's URL](0020-payment-request-notified-on-method-url.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
