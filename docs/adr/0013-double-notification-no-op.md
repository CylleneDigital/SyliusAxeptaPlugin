# 0013. A double notification is a no-op: transitions are guarded, no lock is taken

- Status: accepted
- Date: 2026-10-20

## Context

The bank may notify the same payment more than once: a double notification is a nominal case, not
an anomaly. Applying a transition that no longer applies would fail, and a failure on the
notification URL makes the bank retry.

## Decision

Every transition a notification triggers is guarded by the state machine's `can()`. A notification
whose transition no longer applies changes nothing and is logged as having no effect, distinctly
from one that was applied. No lock is taken around the handling.

## Consequences

- A replay on a payment already completed, or refunded since, is a no-op.
- A paid notification on a payment that can no longer complete, failed or cancelled, is not a
  replay: it is logged as an error, the bank having taken money that settles no order.
- Two notifications of the same payment arriving at the same instant can both pass the guard and
  both apply the transition, so whatever the transition triggers outside the database may run twice.
  On either path the second can fail when its changes are written, on a version conflict for
  instance, and then answers a 500 with its database changes rolled back; the bank's retry finds the
  payment settled. Otherwise both answer 2xx. Accepted: never seen from the bank.

## Related

- [0012. A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404](0012-notification-answers-2xx-deliberate-404.md)
- [0016. Any attempt can pay a payment, only the latest can refuse it](0016-any-attempt-pays-latest-refuses.md)
- [0018. The plugin logs to its own channel, at a level set by what is at stake](0018-own-log-channel-level-by-stake.md)
