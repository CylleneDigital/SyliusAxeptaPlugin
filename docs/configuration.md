# Configuration

## The gateway settings

They are entered in the back office, on the payment method. They are **never** versioned nor loaded
by fixtures.

| Key | Required | Role |
|---|---|---|
| `merchant_id` | yes | Merchant identifier (MID) supplied by BNP |
| `hmac_key` | yes | Signs requests and authenticates notifications |
| `blowfish_key` | yes | Encrypts the data sent to the payment page |
| `test_mode` | no | Forces `OrderDesc=Test:0000` |
| `language` | no | Language of the payment page; empty means the order's |

### ⚠️ The MID determines the environment

**There is no test URL.** The test environment uses the same endpoints as production; only the
`MerchantID` tells them apart. A test MID and a production MID are two different identifiers on the
same platform.

Consequence: **a wrong MID sends real transactions to production.** Check it before any test
campaign.

### What "test mode" actually does

This setting switches no URL. It forces the order description to `Test:0000`, the value required by
the generic demonstration account BNP publishes - without it, test cards are refused with no
explanation.

Tick it **only** if you are using that generic account. With a dedicated test MID, leave it
unticked: your real order descriptions are then sent through.

### `AUTHORIZED` counts as paid

The plugin settles the payment on `OK` and on `AUTHORIZED` alike. It sends no capture mode, so BNP
applies its default, automatic capture. BNP's documentation does not define how `OK` and
`AUTHORIZED` differ; on a MID set to **manual capture**, an order would turn paid as soon as the card
is authorised, before any capture. Keep your MID on automatic capture, or check that point with your
account manager.

### The currency

Only currencies with two decimals are supported - the euro first. The plugin does not check the
channel's currency: see the amount sent in clear in [protocol.md](protocol.md).

### The `payment_request` transport stays synchronous

On the PaymentRequest path, the redirect page is rendered from what the capture stores, in the same
HTTP request. Keep the `payment_request` Messenger transport synchronous (`sync://`, the Sylius
default): handled by a worker, the capture comes too late for the customer, who is not redirected to
the bank, and its absolute URLs are built for the router's default host - the bank could neither send
the customer back nor notify. The plugin logs that case as an `error`.

## Changing the configuration

Both key fields appear **blank** when the form is reopened: that is the normal behaviour of a
password field, which never redisplays its value.

Saving without retyping them **does not erase them** - the plugin keeps the existing values.
Corollary: a key cannot be cleared from the form, only replaced with another one.

## Key rotation

To be done if your keys may have leaked - a commit, a screenshot, a chat channel - or according to
your internal policy.

### The procedure

1. Ask BNP for a new key pair. The rotation concerns both keys, HMAC and Blowfish: replace them
   together.
2. **Pick a low-traffic window.**
3. Update the payment method in the back office.
4. Watch the logs over the following hours.

### The pitfall: payments in flight

A customer redirected to the payment page **before** the rotation will notify **after**, signed with
the old key. Their notification will be rejected, and their payment will stay pending although they
have paid.

An "instant" rotation at peak hour therefore fails every payment in progress. There is no way around
it in this version: the plugin only accepts one HMAC key at a time. Support for a secondary key,
accepted for verification during the switchover, is a candidate for a later version.

### What to watch

Two symptoms, distinct, and both silent if nobody looks:

- **on the inbound side**: rejected notifications, logged as `warning` with the `invalid_signature`
  reason;
- **on the outbound side**: BNP refuses our requests with code `20100044` or `20120044`. Those
  transactions **do not appear in the BNP back office** - a manual review on the bank side will not
  see them. The plugin logs this case with a dedicated message.

```bash
grep -c '"reason":"invalid_signature"' var/log/prod.log
```

## Logging

The plugin writes to a dedicated Monolog channel, `axepta` by default. Wire whatever you like to it:
a file, Sentry, a SIEM.

```yaml
# config/packages/cyllene_digital_sylius_axepta.yaml
cyllene_digital_sylius_axepta:
    logger_channel: 'axepta'
    payment_page_url: 'https://paymentpage.axepta.bnpparibas/payssl.aspx'
```

⚠️ **`payment_page_url` is there for unusual environments, not to change product.** In particular, do
not point it at `paymentpage.aspx`: that endpoint also offers the alternative payment means, which
notify over GET - which this plugin does not handle yet. The customer would pay, and the order would
stay unpaid. See [protocol.md](protocol.md).

Both paths, unless the column says otherwise:

| Event | Level | Path |
|---|---|---|
| Payment request built | `info` | both |
| Capture handled outside an HTTP request (asynchronous `payment_request` transport) | `error` | PaymentRequest |
| Notification applied | `info` | both |
| Notification with no effect (transition no longer applies, replay) | `info` | both |
| Refusal of a superseded attempt, ignored | `info` | Payum |
| Notification without an encrypted payload | `warning` | both |
| Notification not authenticated | `warning` | both |
| Notification rejected on a signature code `*0044` | `warning`, dedicated message | both |
| Notification with an unknown status | `warning` | both |
| Genuine notification over GET (alternative payment means; answered with a 404 on the PaymentRequest path) | `error` | both |
| Paid notification on a payment that can no longer complete (failed, cancelled, unknown) | `error` | both |
| Notification that cannot be tied to this payment | `error` | Payum |
| Authenticated notification matching no payment of the method (answered with a 404) | `error` | PaymentRequest |
| Notification on a payment method whose configuration is incomplete (answered with a 404) | `error` | PaymentRequest |
| Handling failure | `error` | both |

**No key, no encrypted payload and no signature is ever logged** - the signature is a value derived
from the shared secret.

In production, keep `zend.exception_ignore_args=On`, the default of `php.ini-production`. The
plugin marks its keys as sensitive parameters, but a stack trace logged with its arguments can still
carry what passed through the notification handling - the encrypted payload, for one.

The usual Monolog production setup, the one of the Symfony recipe, excludes 404 responses from its
error logs (`excluded_http_codes`). The PaymentRequest path answers some notifications with a 404 on
purpose: they will not show up as request errors, and the `axepta` channel is where their reason is
logged. Watch that channel, not only the HTTP error log.

`payment_page_url` exists to absorb an endpoint change on the BNP side without waiting for a plugin
release. It is **not** a way to switch between test and production.

## Customising

### The redirect page

This is the page the customer sees between your shop and the bank.

```
templates/bundles/CylleneDigitalSyliusAxeptaPlugin/shop/payment/axepta_redirect.html.twig
```

It must stay usable **without JavaScript**: keep the button inside `<noscript>`, otherwise a hardened
browser or a screen reader can no longer pay.

### The configuration fields in the back office

The hookable is called `axepta_fields`:

```yaml
sylius_twig_hooks:
    hooks:
        'sylius_admin.payment_method.update.content.form.sections.gateway_configuration.axepta':
            axepta_fields:
                enabled: false
            my_fields:
                template: 'admin/axepta.html.twig'
```

### The merchant reference and the transaction identifier

Two substitutable interfaces, should your bank reconciliation conventions differ:

```yaml
services:
    CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\ReferenceProviderInterface:
        alias: App\Payment\MyReferenceProvider

    CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\TransactionIdGeneratorInterface:
        alias: App\Payment\MyTransactionIdGenerator
```

⚠️ The `RefNr` must be **exactly 12 alphanumeric characters** and the `TransID` **64 at most**, while
staying unique per payment - a collision ties a notification to the wrong payment.

> ⚠️ **Substituting the transaction identifier generator requires supplying its inverse.**
> `resolvePaymentIdentifier()` is what the PaymentRequest path uses to find the notified payment, by
> primary key, since its notification URL is shared across the payment method and carries no token
> of its own. The Payum path uses it too, to accept the notification of an earlier attempt of a
> payment: reopening the payment page renews the `TransID`, while the bank page of the earlier
> attempt can still be paid.
>
> On the PaymentRequest path, a generator that emits identifiers its own inverse does not recognise
> leaves every notification unattached: the bank retries eight times over ~21 h and then gives up,
> on orders the customer has paid for. On the Payum path, it only loses the earlier attempts: the
> latest one is still matched on its stored `TransID`. The interface documents the contract on both
> methods.
