# Acceptance testing on the BNP test environment

This runbook describes the real payment cycle, the one no automated test can replace. It is walked
through **before every major version**, on both payment paths.

## ⚠️ There is no sandbox URL

The test environment uses **the same endpoints** as production. The `MerchantID` is what determines
the environment - a test MID and a production MID are two distinct identifiers on the same platform.

Direct consequence: **a configuration mistake sends real transactions to production.** Check the MID
before every campaign.

## Requirements

| | |
|---|---|
| Test MID | Requested from your BNP account manager. The generic demonstration account below will do in a pinch, but has no back-office access |
| Public URL | The bank must reach your notification URL. `ngrok`, `cloudflared` or equivalent |
| Test cards | **Published by BNP**, see the dedicated section below - no need to wait for the MID |

### The generic demonstration account

BNP publishes in its documentation an account usable without any formality
([Test environment](https://docs.axepta.bnpparibas/display/DOCBNP/Environnement+de+Test)):

| | |
|---|---|
| MID | `BNP_DEMO_AXEPTA` |
| HMAC key | `4n!BmF3_?9oJ2Q*z(iD7q6[RSb5)a]A8` |
| Blowfish key | `Tc5*2D_xs7B[6E?w` |
| Constraints | `MsgVer=2.0`, and **`OrderDesc` must be exactly `Test:0000`** |
| Accepted cards | Visa and Mastercard only |

These values are **public**: they are not secrets and must not be treated as such. A secret scanner
will flag them anyway - that is a false positive, to be handled as one rather than by removing them
from here. They are still entered in the back office, never in fixtures nor in versioned
configuration: the rule holds for every key, including those protecting nothing.

Tick **"Test mode"** on the payment method - that is precisely what the setting does, force
`OrderDesc=Test:0000`. Without it, test cards are refused with no explanation.

## The test cards

BNP publishes two sets, which do not serve the same purpose. Picking from the wrong one wastes time:
an authorisation card will tell you nothing about 3-D Secure, and vice versa.

### Authorisation - choosing the bank's verdict

**The table is authoritative, not this document.** It is here:
[Test cards - Authorisation](https://docs.axepta.bnpparibas/display/DOCBNP/Cartes+de+test+-+Autorisation).
Each number is tied there to a predefined response code. Look up the number matching the verdict you
want to provoke yourself; do not copy a second-hand list, this one included.

Expiry date: any date in the future. Security code: optional.

### ⚠️ The demonstration account runs out **[observed]**

**After a dozen transactions it refuses everything** - all cards, all amounts, both payment paths,
under a single unchanging code (`2294014B`, CB2A France module). Nothing signals a cap: the refusal
has every appearance of an ordinary bank rejection.

Found after an hour spent looking for the cause inside the plugin. The same cycles, taken up again
on a dedicated test MID, passed on the first try.

Its verdicts are not reliable while it still answers anyway: a card advertised as refused was
authorised there, and a card advertised as accepted was refused.

**Use it to rough things out, not to run acceptance.** A serious campaign needs a dedicated MID.

### Authentication - choosing the 3-D Secure journey

Useful only to exercise the authentication journeys: challenge, frictionless, authentication
failure. Source:
[Test cards - Authentication](https://docs.axepta.bnpparibas/display/DOCBNP/Cartes+de+test+-+Authentification).

| Visa | Journey |
|---|---|
| `4000019966199434` | authenticated, frictionless |
| `4000011744135012` | **not** authenticated, frictionless |
| `4000012892688323` | browser challenge |
| `4000017873485953` | authentication protocol error |

A **challenge is not a refusal**: it is the authentication screen, a normal step. Do not count these
cards as payment failures.

The page offers eight scenarios, each in a Visa and a Mastercard flavour; the four above are the
ones a gateway acceptance run needs. Refer to the source for the others.

## 1. Expose the application

The return and notification URLs are generated absolutely from the current host. **Browse the shop
through the public URL**, not through `localhost`, otherwise the bank will receive a notification URL
it cannot reach.

```bash
cloudflared tunnel --url http://localhost:8080
```

### Trusted proxies

Behind a tunnel, Symfony only sees the container host. Without trusted proxies it generates the URLs
with that host, and the bank receives a notification URL it cannot reach.

**On the plugin's test application there is nothing to do**:
`tests/TestApplication/config/config.yaml` already configures them.

**On your own shop**, add:

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: 'private_ranges'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
```

`x-forwarded-host` is the one not to forget: Symfony does **not** trust it by default, and yet it is
the only header carrying the tunnel domain. Without it the host stays wrong even with the proxies
declared.

> ⚠️ If you would rather drive the value from an environment variable, **do not name it
> `TRUSTED_PROXIES`** when your `public/index.php` comes from the Sylius skeleton: it reads that
> variable and calls `Request::HEADER_X_FORWARDED_ALL`, a constant removed in Symfony 6. Setting it
> replaces the shop with a fatal error.

## 2. Configure the payment method

In the back office, **Configuration → Payment methods → Create**, gateway "Axepta - BNP Paribas".
Enter the MID, the HMAC key and the Blowfish key supplied by BNP.

The path is chosen through the `usePayum` boolean of the gateway configuration
(see [payum-vs-payment-request.md](payum-vs-payment-request.md)). **Both paths must be exercised.**

Since that setting is only editable **at creation time**, create two payment methods - one per path -
rather than hoping to switch one mid-campaign. Name them distinctly: both will show up in the
checkout, and the run only means something if you know which one you are exercising.

## 3. The nominal cycle

1. Place an order through to payment, pick Axepta.
2. Check the redirect to `paymentpage.axepta.bnpparibas`.
3. Pay with `4000019966199434` on the demonstration account (see the cards section for why), or with
   a card from the authorisation table on a dedicated MID.
4. Check: payment `completed`, order `paid`.

The refusal case expects the same sequence, with a card whose verdict is a failure: payment `failed`,
**and a new `new` payment** created by Sylius on the order, which stays in `awaiting_payment` - that
is what lets the customer retry without placing the order again.

⚠️ Provoking a refusal at will is not a given on the demonstration account: see the caveat in the
test cards section.

### ⚠️ Reaching the payment page does not validate the signature **[observed]**

**The MAC is not checked when the page is displayed.** Verified on the demonstration account: a
request signed with a deliberately wrong HMAC key displays the payment page exactly like a valid one
- same amount, same description, same card form. The rejection only comes on submitting the payment,
as a `20100044` or `20120044` code.

What the display does prove is that **the Blowfish encryption is right**: the platform managed to
decrypt the payload and read the amount, the currency and the description from it. That is already a
lot - a wrong `Len` or a UTF-8 encoding would fail here. But it is **not** a validation of the
signature.

Practical consequence: conclude nothing from a successful redirect. **The first real checkpoint of
the protocol is the payment actually taken**, not the page reached. A wrong HMAC key is discovered at
step 3, never at step 2.

## 4. The checklist

Eight points to go through before opening payment to your customers. None is covered by the plugin's
automated tests: they all bear on what the bank actually answers, or on your infrastructure.

| # | Check | Expected |
|---|---|---|
| 1 | Nominal cycle, **Payum** path | Payment `completed`, order `paid` |
| 2 | Nominal cycle, **PaymentRequest** path | Same. To be done if you use that path |
| 3 | Refused card | Payment `failed`, a fresh attempt possible from the order |
| 4 | Replayed notification | No effect, and a **200 response** |
| 5 | Tampered signature | Rejected, no state changed, `warning` log |
| 6 | **12-character `RefNr`** | The BNP back office does show 12 characters padded with zeroes |
| 7 | **Accented `OrderDesc`** | An order whose description carries an accent settles: valid signature, readable description on the payment page |
| 8 | **Generic demonstration account** | With "Test mode" ticked, the test cards go through |

### Point 4 is checked over a 22-hour window

BNP's documentation establishes that a **404 or a 500 triggers 8 retries spread over ~21 h 36**. It
does **not** write that a 200 stops them; observation corroborated it - 19 payments notified, one
notification each.

The check stays in the runbook because it costs nothing and catches something else: a notification
URL that would answer badly on your side, on your infrastructure. Provoke a double notification, then
**watch for 24 hours** that no retry follows. Count the calls received:

```bash
# On the PaymentRequest path, the notification URL is fixed:
grep -c 'POST /payment-methods/' var/log/prod.log
```

One line per legitimate notification. If the counter climbs on its own overnight, the `200` is not to
blame - your notification URL is not answering what you think: reverse proxy, redirect, firewall.
Look at the code actually returned to BNP before suspecting the plugin.

### Point 7 is the most important of the campaign

An integration whose order descriptions are pure ASCII never meets the encoding problem, and can run
for years without seeing it. Handled badly, it only breaks **a fraction** of orders, those whose
description carries an accent: the worst profile of bug to diagnose in production.

Point 6 is read at the same moment, but **in the BNP back office**: the reference appears there on
twelve characters, left-padded with zeroes. So plan for a MID whose back office you also have access
to, otherwise this point stays unverifiable.

The description is not free-form: it is composed by Sylius' description provider, which translates it
from the `sylius.payum_action.payment.description` key - pure ASCII in every shipped locale. To make
it accented, override that key in your application:

```yaml
# translations/messages+intl-icu.fr.yml
sylius:
    payum_action:
        payment:
            description: '{items, plural, one {Commande — contrôle d''encodage « éàû » : # article pour un total de {total}} other {Commande — contrôle d''encodage « éàû » : # articles pour un total de {total}}}'
```

That is exactly the override the test application carries
(`tests/TestApplication/translations/`), and it is chosen to mix two families of characters: `é`,
`à`, `û`, `«` and `»` exist in ISO-8859-1 and must come through intact; the em dash `—` does not
exist there and must degrade to `?` without breaking the payload.

> ⚠️ **Point 7 cannot be tested on the demonstration account.** That MID requires
> `OrderDesc=Test:0000`, which is precisely what "Test mode" forces: the accented description never
> leaves the shop. Unticking the setting would get the test cards refused.
>
> **Keep this point for your own test MID.** That is how it was validated on the plugin side:
> payload decrypted and read back at byte level, `é` as `0xE9`, `à` as `0xE0`, `ô` as `0xF4`, `Len`
> announced equal to the real byte count, and the payment settles - which amounts to the MAC computed
> on that payload being accepted.

## 5. Record the outcome

For each point, note: the date, the MID used, the payment path, the `TransID`, the `PayID` and the
`XID`. The last two are the reconciliation keys with the BNP back office in case of dispute, and the
plugin logs them on its dedicated channel.

Any divergence between the observed behaviour and [`protocol.md`](protocol.md) must be reported
there: **observation on the test environment is authoritative over the documentation**, including
when the two contradict each other.

## After the campaign

If the keys used may have leaked - a commit, a screenshot, a chat channel - ask BNP for a rotation.
Mind the payments in flight: a notification signed with the old key arriving after the rotation is
rejected with a `20100044` or `20120044` code, which the plugin logs with a dedicated message.
