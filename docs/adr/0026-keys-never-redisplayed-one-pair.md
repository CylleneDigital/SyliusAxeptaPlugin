# 0026. Keys are never redisplayed, survive a blank save, and one pair is active at a time

- Status: accepted
- Date: 2026-10-20

## Context

The HMAC and Blowfish keys are entered in the back office. A password field never redisplays its
value, so editing a payment method shows both keys blank; since both are required, every save would
then be refused until both keys are retyped.

## Decision

The key fields never redisplay a stored key, not even after a submission that fails validation on
another field. A form listener keeps the stored key when its field comes back blank. The gateway
holds one key pair, HMAC and Blowfish, at a time.

## Consequences

- Saving a payment method without retyping its keys keeps them, and passes the required-field
  validation.
- A key cannot be cleared from the form, only replaced: accepted for a value whose absence breaks
  every payment.
- A payment sent before a rotation and notified after it carries a signature of the old key and
  fails verification; only the PaymentRequest path's 404 brings the bank's retries, which recover it
  if the old key is restored in time. Rotating in a low-traffic window limits it.

## Related

- [0012. A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404](0012-notification-answers-2xx-deliberate-404.md)
- [0024. The back-office fields are rendered through an optional Twig hook](0024-optional-twig-hook.md)
- [0029. Gateway configuration keys tolerate their own absence](0029-configuration-keys-tolerate-absence.md)
- [0030. Secrets stay out of logs, dumps, traces and serialisation](0030-secrets-out-of-logs-dumps-traces.md)
