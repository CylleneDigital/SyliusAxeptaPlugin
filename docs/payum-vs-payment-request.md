# Payum or PaymentRequest?

Sylius 2 lets two payment mechanisms coexist. This plugin implements both on a shared core: the
Axepta protocol is the same, only the way Sylius triggers it changes.

The choice is made per payment method, in the back office, through the `usePayum` boolean of the
gateway configuration.

> ⚠️ **The choice is frozen at creation time.** Sylius disables the checkbox as soon as the payment
> method exists - switching a live payment mean would leave the payments already sent to the bank
> with no mechanism to handle their return.
>
> To try the other path, **create a second payment method**. Nothing forbids having two on the same
> gateway: that is in fact how to migrate without interruption, disabling the old one once the
> payments in flight have drained.

## Which one to pick

| Your situation | Path |
|---|---|
| Standard Sylius checkout | **Payum** |
| API, headless, decoupled front end | **PaymentRequest** |
| You do not know | **Payum** - it is Sylius' default, and the foundation it is stable on |

The Payum path is stable and battle-tested. The PaymentRequest path is the only one usable from the
API - `PaymentRequest` is an API Platform resource, Payum is not.

## ⚠️ Status of the PaymentRequest path

Sylius' `PaymentRequest` foundation is marked **`@experimental`** by Sylius itself: its contract may
change from one minor version to the next. The classes of this adapter carry the same marker, and
their evolution to follow Sylius will not be considered a compatibility break of our doing.

**Both paths have taken a real payment on the BNP test environment**, notification and signature
verification included, as well as the refused card case.

That does not put them on equal footing: the Payum path is the one of the default checkout,
exercised by many other gateways than this one. If you have no reason to take the other, take Payum.

## What actually differs

| | Payum | PaymentRequest |
|---|---|---|
| Triggering | `PayumPayResponseProvider` → token | `PaymentRequestPayAction` |
| Execution | Payum actions | Messenger commands on `sylius.payment_request.command_bus` |
| State tracking | `GetStatusInterface` | `sylius_payment_request` state machine |
| Notification URL | Payum token, specific to the payment | `/payment-methods/{code}`, fixed |
| Customer return URL | `/axepta/return/{paymentId}/{signature}` | identical |
| API Platform | Not covered | Covered |

In both cases the redirect page is the same, the protocol is the same, and **the notification is
what counts** - never the browser coming back. A customer who closes their tab after paying must
still see their order turn paid.

## Why the notification URL differs

This is the only substantive divergence between the two paths, and it deserves an explanation.

Sylius offers two routes to receive a notification:

| Route | Path |
|---|---|
| `sylius_payment_request_notify` | `/payment-requests/{hash}` |
| `sylius_payment_method_notify` | `/payment-methods/{code}` |

This plugin uses the **second**. The first looks more natural - the URL designates exactly the
payment request concerned - but it does not suit an off-site gateway, for two reasons drawn from the
code of `PaymentRequestNotifyAction`:

1. **It answers 404 as soon as the request is in a final state.** Yet BNP's documentation establishes
   that a 404 triggers **8 retries spread over ~21 h 36**. A double notification, the nominal case at
   BNP, would therefore produce close to 22 hours of retries for a payment already handled
   correctly. And the 404 is raised **before** `NotifyResponseProviderInterface` is consulted: no
   response provider can fix it.
2. **It replays the action of the targeted request**, that is `capture`. The capture handler would be
   the one called back on receiving the notification, not the notification handler.

`/payment-methods/{code}` has neither flaw: every notification creates a **fresh** request there
under the `notify` action. There is therefore never a final state to hit, and the right command goes
out.

In exchange, the URL does not say which payment the notification refers to.
`AxeptaNotifyPaymentProvider` resolves that from the `TransID` - the only identifier the platform
returns reliably, since it is part of the response signature.

The resolution goes through **the inverse of the generator that emitted the identifier**,
`TransactionIdGeneratorInterface::resolvePaymentIdentifier()`, which reads the payment by primary
key. That inverse is part of the contract, not a naming convention guessed by the caller:
substituting the generator therefore requires supplying its inverse.

> An earlier version scanned the `TransID` of the 500 most recently updated captures instead, so as
> not to depend on the identifier format. The cure was worse than the disease: opening 500 payments
> and abandoning them was enough to push a real customer's capture out of the window, after which
> their notification went to a 404 and the order stayed unpaid while the money had been taken.

## Answering notifications

The Sylius foundation answers `204 No Content`. This plugin answers **`200 OK`** on its own
notifications: BNP confirmed that a `200` stops the retries, and nowhere writes that a `204` would
do. On a payment plugin, the confirmed code beats the gamble.

The decoration only applies to requests of the `axepta` gateway: the other gateways of your
application keep their behaviour.

## Switching from one path to the other

`usePayum` changes on the payment method, with no data migration: the gateway configuration is the
same on both sides.

Do not change it while payments are in flight. A payment captured by one path must be notified by
the same one - the notification URL handed to the bank at capture time does not change
retroactively.
