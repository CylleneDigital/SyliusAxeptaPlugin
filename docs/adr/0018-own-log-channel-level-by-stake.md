# 0018. The plugin logs to its own channel, at a level set by what is at stake

- Status: accepted
- Date: 2026-10-20

## Context

Most of what goes wrong with a notification never reaches the customer nor the HTTP error log: a
replay, a forged message, a payment taken that settles no order. The log is where an operator finds
it, and the notification URL is open to anyone: a level an unknown sender can trigger at will would
flood alerting.

## Decision

The plugin logs to a dedicated channel, whose name the bundle configuration sets. Levels follow what
is at stake:

- warning for what an unknown sender can produce: a message without an encrypted payload, one that
  fails authentication, and an unknown status;
- error when money may be at stake or the configuration is broken: an authenticated notification
  that cannot be tied to its payment, a notification whose handling throws, a paid notification on a
  payment that can no longer complete, a notification of an alternative payment means, a payment
  method left incomplete, a capture handled outside the customer's request;
- info for the nominal flow: a request built, a notification applied, a replay with no effect, a
  refusal of a superseded attempt.

No secret is logged ([0030](0030-secrets-out-of-logs-dumps-traces.md)).

## Consequences

- A shop routes the channel where it wants, and can alert on errors without being flooded by
  internet noise.
- An error from the channel calls for a reconciliation with the bank's back office: the logged
  identifiers are the reconciliation keys.
- A rejected message logs its reason and source, never what would have made it acceptable.

## Related

- [0012. A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404](0012-notification-answers-2xx-deliberate-404.md)
- [0014. An unknown status changes nothing](0014-unknown-status-changes-nothing.md)
- [0030. Secrets stay out of logs, dumps, traces and serialisation](0030-secrets-out-of-logs-dumps-traces.md)
