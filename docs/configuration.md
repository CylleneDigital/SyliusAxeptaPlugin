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

## Changing the configuration

Both key fields appear **blank** when the form is reopened: that is the normal behaviour of a
password field, which never redisplays its value.

Saving without retyping them **does not erase them** - the plugin keeps the existing values.
Corollary: a key cannot be cleared from the form, only replaced with another one.

## Key rotation

To be done if your keys may have leaked - a commit, a screenshot, a chat channel - or according to
your internal policy.

### The procedure

1. Ask BNP for a new key pair.
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

| Event | Level |
|---|---|
| Payment request built | `info` |
| Notification applied | `info` |
| Notification with no effect (transition already played) | `info` |
| Notification rejected | `warning` |
| Signature code `*0044` received | `warning`, dedicated message |
| Handling failure | `error` |

**No key, no encrypted payload and no signature is ever logged** - the signature is a value derived
from the shared secret.

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
> of its own.
>
> A generator that emits identifiers its own inverse does not recognise leaves every notification
> unattached: the bank retries eight times over ~21 h and then gives up, on orders the customer has
> paid for. The interface documents the contract on both methods.
