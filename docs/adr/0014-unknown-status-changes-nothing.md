# 0014. An unknown status changes nothing

- Status: accepted
- Date: 2026-10-20

## Context

The bank documents three statuses for a notification: two that mean the card was accepted, one that
means refused ([0003](0003-one-off-card-payments-automatic-capture.md) reads both acceptances as
paid). Nothing guarantees that list is closed: the bank asks to tolerate unknown parameters, nothing
says the status values are a closed set either, and a new value would arrive unannounced. An
authenticated notification can therefore carry a status the plugin does not know, about a payment
that may or may not have been taken.

## Decision

An authenticated notification with a status outside the known ones triggers no transition, and is
logged as a warning with its response code. The status is read tolerating case and surrounding
whitespace. Marking the payment as refused would be the more destructive mistake: a payment taken
would leave its order unpaid and open to a second payment.

## Consequences

- The payment stays in its state; a later notification, or a manual settlement, both remain
  possible.
- The response code, logged with the warning, is what tells an operator what the bank meant.
- Supporting a new status is a change of the plugin, resting on the bank's documentation.

## Related

- [0003. One-off card payments with the bank's automatic capture](0003-one-off-card-payments-automatic-capture.md)
- [0005. Every protocol rule rests on the bank's documentation, an official vector or an observed behaviour](0005-protocol-rules-rest-on-evidence.md)
- [0018. The plugin logs to its own channel, at a level set by what is at stake](0018-own-log-channel-level-by-stake.md)
