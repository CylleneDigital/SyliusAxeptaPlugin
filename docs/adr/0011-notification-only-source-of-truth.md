# 0011. Only the server-to-server notification changes a payment, and the bank is never queried

- Status: accepted
- Date: 2026-10-20

## Context

An off-site payment reaches the shop by two ways: the customer's browser coming back from the bank,
and the notification the bank sends server to server. The bank's documentation makes the
notification the one that counts. The browser may come back before the notification, twice, or
never: the customer can close the tab right after paying. Querying the bank for the outcome would
need its server-to-server interface, which the plugin does not use.

## Decision

Only an authenticated notification moves a payment. The customer's return, the Payum status action
and the PaymentRequest status handler decide nothing: they read the payment's current state and
report it. None of them queries the bank. Off site, the capture only redirects: on the PaymentRequest
path it leaves its request in processing, and the payment completes or fails with the notification.

## Consequences

- A customer who closes the tab after paying still has the order paid.
- A notification that never arrives leaves the order unpaid while the bank has taken the money: the
  notification URL must stay reachable by the bank, and such a payment is settled by hand.

## Related

- [0012. A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404](0012-notification-answers-2xx-deliberate-404.md)
- [0015. A notification is authenticated on its encrypted payload alone, against the configured merchant identifier](0015-authenticated-on-encrypted-payload-configured-merchant.md)
- [0022. The customer returns through the plugin's own signed route, which decides nothing](0022-own-signed-return-route.md)
- [0025. The PaymentRequest capture runs inside the customer's request](0025-capture-inside-customer-request.md)
