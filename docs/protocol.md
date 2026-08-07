# The Axepta protocol, as implemented

This document describes what the plugin sends and what it accepts. It serves two purposes:
understanding an exchange in production, and telling what is **guaranteed by BNP's documentation**
from what is **observed on the platform**.

Every statement is tagged **[doc]** or **[observed]**. The distinction matters: what is observed can
change without notice.

The authoritative reference: <https://docs.axepta.bnpparibas>.

## Overview

This plugin implements **Axepta BNP Paribas Online 1.0**, the generation with `.aspx` endpoints
(Blowfish encryption, HMAC signature). Generation **2.0** is a separate REST API with OAuth 2.0
authentication: it is not a version of the same protocol, and this plugin does not cover it.

An off-site redirect gateway:

1. the shop builds a signed and encrypted form, self-submitted to
   `https://paymentpage.axepta.bnpparibas/payssl.aspx` **[doc]**;
2. the customer enters their card at BNP - the shop never sees a number;
3. BNP notifies the shop server-to-server;
4. BNP sends the browser back to the success or failure URL.

**The notification is what counts, never the browser coming back** **[doc]**. The customer may close
their tab after paying; the order must turn paid all the same.

## Cryptography

| Element | Algorithm |
|---|---|
| `Data` | Blowfish-ECB, key repeated up to 16 bytes, null-byte padding, hexadecimal output |
| `MAC` | HMAC-SHA256, hexadecimal output |

### Composing the signature **[doc]**

```
Request      MAC = HMAC-SHA256( PayID * TransID * MerchantID * Amount * Currency )
Notification MAC = HMAC-SHA256( PayID * TransID * MerchantID * Status  * Code     )
```

A missing field becomes an empty string **and the separator stays**. On the way out `PayID` is
empty, the bank has not assigned it yet: the message therefore starts with `*`.

The plugin is checked against the **five official vectors** published by BNP. They are worth more
than any in-house test: they validate the algorithm against a reference we did not write.

## The payment request

### Fields sent in clear

| Field | Content |
|---|---|
| `MerchantID` | Merchant identifier |
| `TransID` | Transaction identifier |
| `Amount` | Amount **in euros** (`42.00`) |
| `Len` | Length of the plaintext payload, in bytes |
| `Data` | Encrypted payload, in hexadecimal |

### ⚠️ The amount goes out twice, in two units **[observed]**

The amount appears **in cents** inside the encrypted payload, as the documentation says, and **in
euros** on the visible field. That second field appears in no specification, but the payment page
rejects cents at that spot.

This is observed, unspecified behaviour - hence liable to change, and covered by a test.

### Encrypted payload

Format `k=v&k=v`, in this order, empty fields being omitted:

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

Verified against the platform, at byte level: `é` goes out as `0xE9`, `à` as `0xE0`, `Len` does count
the converted bytes, and the payment settles - so the MAC computed on that payload is accepted.

#### What ISO-8859-1 cannot represent

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

The plugin reduces any reference to the `RefNr` format: non-alphanumeric characters stripped, last
twelve kept, padded with zeroes. A description containing `&` or `=` has those characters
neutralised - they would cut the payload when read back.

## The notification

### Transport **[doc]**

| Aspect | Value |
|---|---|
| Method | **POST** for cards; GET for alternative payment means |
| Content-Type | `application/x-www-form-urlencoded; charset=iso-8859-1` |
| Port | 443 only |

> **Limitation of this version**: only POST is handled. The alternative payment means (Floa,
> Instanea) notify over GET and are not in scope yet.
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

`PayID` and `XID` are logged: they are the reconciliation keys with the BNP back office in case of
dispute.

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

The whole design of the handling follows from this: no exception surfaces, an unreadable message is
a non-event, and a double notification - the nominal case at BNP - is a no-op rather than an error.

> **That a `200` stops the retries is confirmed** by observation: across 19 payments notified in the
> test environment, exactly one notification each, no spontaneous retry. The public documentation
> does not write it; answer `200`, not `204`.

### Reading tolerances **[doc]**

The documentation explicitly asks to:

- compare parameter names **case-insensitively**;
- **not rely on their order**;
- **ignore gracefully** unknown parameters, new ones being liable to appear without notice.

The plugin does all three.

## Three discrepancies found in real conditions **[observed]**

Invisible in unit tests: vectors you build yourself reproduce them "cleanly". Only a genuine
notification revealed them, and each has its regression test.

| Discrepancy | Effect without the fix |
|---|---|
| The signature comes back in **uppercase hexadecimal** | No notification is ever authenticated |
| The plaintext is padded with **spaces**, not null bytes | The last value of the payload, the signature, drags spaces and never matches |
| The `MerchantID` is **not returned reliably** in the payload | The signature cannot be recomputed |

On the third point, the plugin recomputes with the `merchant_id` from **its own** configuration,
never the received one. The documentation suggests the opposite; relying on the received value would
let an attacker pick their own, and for a legitimate message the two always coincide.

## Out of scope for this version

Deferred capture, cancellation, refund, tokenisation, instalments, alternative payment means. All of
them require BNP's server-to-server API (`capture.aspx`, `reverse.aspx`, `credit.aspx`), which this
plugin never calls.
