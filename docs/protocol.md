# The Axepta protocol, as implemented

This document describes what the plugin sends and what it accepts. It serves two purposes:
understanding an exchange in production, and telling what is **guaranteed by BNP's documentation**
from what is **observed on the platform**.

Statements are tagged **[doc]** when BNP's documentation states them, **[observed]** when they were
only seen on the platform, and **[design]** when they describe a choice of this plugin rather than a
property of the platform. A tag on a heading covers its whole section, unless a statement carries its
own. The distinction matters: what is observed can change without notice.

The authoritative reference: <https://docs.axepta.bnpparibas>.

## Overview

This plugin implements **Axepta BNP Paribas Online 1.0**, the generation with `.aspx` endpoints
(Blowfish encryption, HMAC signature). Generation **2.0** is a separate REST API with OAuth 2.0
authentication: it is not a version of the same protocol, and this plugin does not cover it.

An off-site redirect gateway:

1. the shop builds a signed and encrypted form, self-submitted to
   `https://paymentpage.axepta.bnpparibas/payssl.aspx` **[doc]**;
2. the customer enters their card at BNP - the shop never sees a number **[doc]**;
3. BNP notifies the shop server-to-server **[doc]**;
4. BNP sends the browser back to the success or failure URL **[doc]**.

**The notification is what counts, never the browser coming back** **[doc]**. The customer may close
their tab after paying; the order must turn paid all the same.

## Cryptography

| Element | Algorithm |
|---|---|
| `Data` | Blowfish-ECB, key repeated up to 16 bytes, null-byte padding, hexadecimal output |
| `MAC` | HMAC-SHA256, hexadecimal output |

Blowfish-ECB and HMAC-SHA256 are what the documentation prescribes **[doc]**. The key repetition and
the null-byte padding are those the bank accepts on our requests **[observed]**.

What the bank sends back is padded with spaces rather than null bytes **[observed]**: the plugin
strips both on decryption. See the discrepancies at the end of this page.

### Composing the signature **[doc]**

```
Request      MAC = HMAC-SHA256( PayID * TransID * MerchantID * Amount * Currency )
Notification MAC = HMAC-SHA256( PayID * TransID * MerchantID * Status  * Code     )
```

A missing field becomes an empty string **and the separator stays**. On the way out `PayID` is
empty, the bank has not assigned it yet: the message therefore starts with `*`.

BNP publishes **five official vectors** **[doc]**; the signature is checked against them
**[design]**. They are worth more than any in-house test: they validate the algorithm against a
reference we did not write. They only cover the HMAC: the Blowfish implementation is checked against
the output of OpenSSL's legacy `BF-ECB` and the standard Blowfish vector, not against a BNP vector
**[design]**.

## The payment request

### Fields sent in clear **[doc]**

| Field | Content |
|---|---|
| `MerchantID` | Merchant identifier |
| `TransID` | Transaction identifier |
| `Amount` | Amount **in major units**, two decimals (`42.00`) **[observed]** |
| `Len` | Length of the plaintext payload, in bytes |
| `Data` | Encrypted payload, in hexadecimal |

### ⚠️ The amount goes out twice, in two units **[observed]**

The amount appears **in cents** inside the encrypted payload, as the documentation says, and **in
major units** on the visible field - euros on a euro channel. That second field appears in no
specification, but the payment page rejects cents at that spot.

This is observed, unspecified behaviour - hence liable to change, and covered by a test.

**Only currencies with two decimals are supported** **[design]**. The visible amount is the amount in
the smallest unit divided by 100 and written with two decimals: for a currency with zero or three
decimals it would not match the encrypted one. The plugin does not check the currency of the
channel; keeping it to a two-decimal currency is up to you.

### Encrypted payload **[doc]**

Format `k=v&k=v`, in this order **[design]**, empty fields being omitted:

```
TransID, Amount, Currency, MAC, RefNr, URLSuccess, URLFailure, URLNotify,
URLBack, Response, OrderDesc, MsgVer, Language
```

### ⚠️ The encoding is ISO-8859-1 **[doc]**

The platform works in ISO-8859-1, not UTF-8. The payload is therefore converted before encryption,
and `Len` counted on the resulting bytes.

Handled badly, this only breaks **a fraction** of orders - those whose description carries an accent.
It is the worst profile of bug to diagnose in production, and the reason an accented order features
in the acceptance runbook.

Verified against the platform, at byte level **[observed]**: `é` goes out as `0xE9`, `à` as
`0xE0`, `Len` does count the converted bytes, and the payment settles - so the MAC computed on that
payload is accepted.

#### What ISO-8859-1 cannot represent **[design]**

The latin-1 set is narrower than it looks. Characters absent from it are **replaced with `?`** in the
description sent to the bank:

| | Fate |
|---|---|
| `é` `à` `ô` `ç` `«` `»` | preserved - one byte each |
| **`€`** euro sign | ⚠️ **becomes `?`** |
| **`’`** typographic apostrophe | ⚠️ **becomes `?`** |
| **`—`** em dash | ⚠️ **becomes `?`** |
| **`œ`** oe ligature | ⚠️ **becomes `?`** |

The first three cases are not theoretical: an amount written `53,02 €`, an apostrophe coming from a
word processor, a dash used for layout. Nothing breaks, the payment settles, but the description
shown to the customer on the payment page, and archived on the bank side, carries `?`.

If that matters to you, transliterate beforehand: `€` to `EUR`, `’` to `'`, `—` to `-`.

### Format constraints **[doc]**

| Field | Constraint |
|---|---|
| `TransID` | `ans`, 64 characters maximum, unique per payment |
| `RefNr` | `an`, **exactly 12 characters**, left-padded with zeroes |
| `OrderDesc` | `ans`, 768 characters; `Test:0000` on the demonstration account |
| `URLSuccess`, `URLFailure`, `URLNotify` | 256 characters, **port 443 only** |
| `Language` | `de`, `en`, `es`, `fr`, `it`, `nl`, `pt` |

The plugin reduces any reference to the `RefNr` format **[design]**: non-alphanumeric characters
stripped, last twelve kept, padded with zeroes. A description containing `&` or `=` has those
characters neutralised - they would cut the payload when read back.

## The notification

### Transport **[doc]**

| Aspect | Value |
|---|---|
| Method | **POST** for cards; GET for alternative payment means |
| Content-Type | `application/x-www-form-urlencoded; charset=iso-8859-1` |
| Port | 443 only |

What a card notification actually carries **[observed]**, on every notification received:

- in clear, **only `Data` and `Len`**, sent as `application/x-www-form-urlencoded;
  charset=iso-8859-1`;
- inside the decrypted payload: `mid` and `refnr` (in lowercase), `PayID`, `XID`, `TransID`, `Type`,
  `CardHolder`, `maskedpan`, `card`, `PCNr`, `CCExpiry`, `CCBrand`, `Code`, `Status`, `Description`
  and `MAC`;
- **no amount and no currency**. Neither is covered by the response signature: what ties the
  notification to the right payment is the `TransID`.

The plugin therefore only reads the decrypted payload **[design]**: clear parameters next to a `Data`
are ignored, and a message without a `Data` string is turned away - the plugin asks for encrypted
responses.

> **Limitation of this version** **[design]**: only POST notifications are applied. The alternative
> payment means (Floa, Instanea) notify over GET and are not in scope yet; a genuine GET
> notification is authenticated and logged as an error, never applied.
>
> That is also why the plugin targets `payssl.aspx`, the hosted **card form**, rather than
> `paymentpage.aspx`, the *Hosted Payment Page* which additionally offers the alternative means.
> Both endpoints speak the same protocol, and `payment_page_url` technically allows switching; but a
> customer then picking Floa or Instanea would pay without their order turning paid, for want of a
> handled notification. **Do not change that URL** until GET is supported.

### Response fields **[doc]**

| Field | Content |
|---|---|
| `Status` | `OK`, `AUTHORIZED`, or `FAILED` |
| `Code` | 8 digits; `00000000` means success |
| `PayID` | Payment identifier assigned by the platform |
| `XID` | Identifier covering every step of the transaction |
| `Description` | Error detail - **analyse `Code`, not `Description`** |

The failure value is **`FAILED`**, not `FAILURE`.

`PayID` and `XID` are logged **[design]**: they are the reconciliation keys with the BNP back office
in case of dispute.

### Reading a response code **[doc]**

The code is 8 alphanumeric characters and breaks down into three parts. That breakdown is what
allows diagnosis without an exhaustive table:

| Position | Role |
|---|---|
| 1 | **Status** |
| 2-4 | **Module** that produced the code |
| 5-8 | Detail: parameter or reason |

Values of the first character:

| | |
|---|---|
| `0` | Success |
| `2` | Error - transaction failed |
| `4` | Fatal error - data possibly lost |
| `6` | Pending - asynchronous final status |
| `7` | Intermediate 3-D Secure step |

Modules met on a French card integration:

| | |
|---|---|
| `100` | Cards, common codes |
| `120` | 3-D Secure, general |
| `206` | 3DS cards through GICC |
| `294` | **CB2A France** - the domestic CB scheme |

A real example: `2294011B` - failure (`2`) reported by CB2A France (`294`), reason `011B`.

**The full table is public**: [A4 Response codes](https://docs.axepta.bnpparibas/display/DOCBNP/A4+Response+codes).
Always analyse `Code`, never `Description`.

### Notable codes **[doc]**

| Code | Meaning |
|---|---|
| `00000000` | Success |
| `20100044`, `20120044` | **Wrong or missing signature.** The transaction is rejected and **does not appear in the BNP back office**. In practice, almost always a badly sequenced key rotation |
| `22720040` | Failure (example cited by the documentation) |

### ⚠️ The retries **[doc]**

If the notification URL answers **404 or 500**, the notification is replayed **8 times, over
~21 h 36**.

> **That a `2xx` stops the retries is observed, not documented** **[observed]**. The Payum path
> answers `204` (the controller of PayumBundle), the PaymentRequest path `200` (the plugin's response
> provider). Across 19, then 10 further payments in the sandbox, on both paths, each received
> exactly one notification; no retry was seen within the observation window, which did not cover
> the ~21 h 36 retry schedule.

What the handling does with that **[design]**:

- a double notification - the nominal case at BNP - is a no-op rather than an error;
- a handling failure on a notification already tied to its payment is logged, not surfaced: the
  payment stays in its state, and the answer is still a `2xx`;
- on the PaymentRequest path, a message that cannot be tied to a payment - no `Data` string, not
  authenticated, over GET, matching no payment of the method, or on a payment method left
  incomplete - gets a **deliberate 404**. Sylius' notification contract leaves no other way out, and
  the retries are useful there: they recover a genuine notification once its cause is fixed - a key
  rotated on one side only, say. A forged message triggers no retry: the bank only retries what it
  sent itself;
- two notifications of the same payment arriving at the same instant can both apply `complete`. On
  either path the second one can then fail when its changes are written, on a version conflict for
  instance, and answer a `500`. Its database changes are rolled back, but what the transition
  triggers outside the database - a listener of the shop sending an email, say - may run twice. The
  bank then retries the `500`, and the retry finds the payment already settled: a no-op.

### Attempts and the payment page **[observed]**

- **On the Payum path, reopening the payment page emits a new `TransID`** - a second tab, or the
  back button replaying the capture. The bank page of the earlier attempt stays payable, and the
  bank then notifies with that earlier `TransID`. The plugin therefore accepts the payment of any
  attempt of a payment, and only counts a refusal on the latest one **[design]**.
- On the PaymentRequest path, Sylius reuses the capture request while it is not final: the payment
  keeps a single `TransID` **[design]**.
- **After a refused card, the bank sends one `FAILED` notification** and returns the customer to the
  shop. The customer is not offered another try on the same bank page (seen on a dedicated test
  MID); a fresh attempt starts from the order.

### Reading tolerances **[doc]**

The documentation explicitly asks to:

- compare parameter names **case-insensitively**;
- **not rely on their order**;
- **ignore gracefully** unknown parameters, new ones being liable to appear without notice.

The plugin does all three **[design]**. It is not a formality: `mid` and `refnr` come back in lowercase
**[observed]**.

## Three discrepancies found in real conditions **[observed]**

Invisible in unit tests: vectors you build yourself reproduce them "cleanly". Only a genuine
notification revealed them, and each has its regression test.

| Discrepancy | Effect without the fix |
|---|---|
| The signature comes back in **uppercase hexadecimal** | No notification is ever authenticated |
| The plaintext is padded with **spaces**, not null bytes | The last value of the payload, the signature, drags spaces and never matches |
| The merchant identifier is **not returned reliably** under the name the documentation gives - it came back as lowercase `mid` | The signature cannot be recomputed from the received value |

On the third point, the plugin recomputes with the `merchant_id` from **its own** configuration,
never the received one **[design]**. The documentation suggests the opposite; relying on the
received value would let an attacker pick their own, and for a legitimate message the two always
coincide.

## Out of scope for this version **[design]**

Deferred capture, cancellation, refund, tokenisation, instalments, alternative payment means. All of
them require BNP's server-to-server API (`capture.aspx`, `reverse.aspx`, `credit.aspx`), which this
plugin never calls.
