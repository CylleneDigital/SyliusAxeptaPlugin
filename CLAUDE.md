# CLAUDE.md

Guide for coding agents working on this repository. It complements, without repeating them:

- [`README.md`](README.md) - what the plugin does, installation, configuration
- [`CONTRIBUTING.md`](CONTRIBUTING.md) - bringing up the environment, QA commands, PR conventions
- [`docs/`](docs/) - documentation aimed at integrators, in particular
  [`docs/protocol.md`](docs/protocol.md), which tells what is **guaranteed by BNP's documentation**
  from what is **observed on the platform**

This file only holds what reading the code will not tell you.

## Where things live

| Path | Role |
|---|---|
| `src/Axepta/` | **Protocol layer** - no dependency on Sylius nor Payum. Testable on its own. |
| `src/Payum/` | Payum adapter: `axepta` factory, capture / notify / status / route actions |
| `src/PaymentRequest/` | `PaymentRequest` adapter - commands, handlers, providers. `@experimental` |
| `src/Controller/`, `src/Renderer/`, `src/Provider/` | Customer return route, transition page, payment resolution |
| `src/Form/Type/` | Gateway configuration form, back office |
| `tests/TestApplication/` | Throwaway Sylius application - test configuration, not plugin code |

The builder and the verifier are **shared services**, whereas the credentials vary per payment
method. Hence signatures taking the credentials as an argument rather than through the constructor:

```php
$builder->build(AxeptaCredentials $credentials, PaymentPageRequestContext $context): PaymentPageRequest
$verifier->verify(AxeptaCredentials $credentials, array $requestParameters): Notification
```

## Three traps that cost half a day

Each fails without a usable message. None is written down anywhere else.

### Behat requires the TestApplication front-end build

Without it, **every admin page comes back as a 500** and says nothing more.

```bash
docker run --rm -v "$PWD":/app -w /app/vendor/sylius/test-application \
  -u "$(id -u):$(id -g)" -e HOME=/tmp node:22-alpine sh -c "yarn install && yarn build"
```

### A file added under `config/services/` is not seen by `cache:clear`

`config/services.xml` imports `services/**`, and the `GlobResource` keeps the list frozen. You need
`rm -rf var/cache/*`. Symptom: the services of the new file do not exist, without the slightest
error.

### The notify payment provider needs an explicit priority

In the test environment Sylius registers a `DummyNotifyPaymentProvider` whose `supports()` answers
true to everything and which returns **the oldest payment in the database**. Symptom if the priority
goes: a 200 response, a `completed` notification request, but on the wrong payment - and only the
first payment in the database appears to work.

## Test database

Sylius encrypts the gateway configuration (`sylius_payment.encryption.enabled` defaults to true).
**Without an encryption key, every write fails on `Cannot read keyfile`.** The key lives in
`vendor/sylius/test-application/config/encryption/` and therefore disappears on every
`composer install`.

```bash
APP_ENV=test vendor/bin/console doctrine:schema:update --force --complete -n
APP_ENV=test vendor/bin/console sylius:payment:generate-key
```

## Invariants not to break

- **`BlowfishEcb` stays pure PHP.** `openssl_encrypt('BF-ECB')` returns `false` under OpenSSL 3 -
  Blowfish sits in the legacy provider, disabled by default. Never "simplify" by wiring OpenSSL back
  in. This is cryptographic code validated against BNP's official vectors.
- **The notify always answers 200.** A 404 or a 500 triggers 8 BNP retries spread over ~21 h 36.
  Hence the dedicated `NotifyResponseProviderInterface` and the systematic catching of exceptions on
  notify. A double notification is the nominal case at BNP, not an anomaly.
- **The payload goes out in ISO-8859-1**, and `Len` counts the converted bytes. Handled badly, this
  only breaks a fraction of orders - those whose description carries an accent.
- **Both payment paths are in scope** from v1. Sylius 2 routes according to
  `GatewayConfig::usePayum`. The classes of the `PaymentRequest` adapter carry `@experimental`, like
  the matching Sylius foundation.
- **`PasswordType` fields require `always_empty: false`** - otherwise saving an existing
  `PaymentMethod` again overwrites the keys with empty strings.
- **New configuration keys must tolerate their own absence**: a gateway configuration written by an
  earlier version does not carry them.
- The public contract - protocol classes, substitutable interfaces, hookable names, service tags,
  gateway configuration keys - only breaks in a major version.

## The protocol is not written from memory

`PaymentPageRequestBuilder` and `NotificationVerifier` are written **from BNP's public
specification** (<https://docs.axepta.bnpparibas>). Any protocol change must rest on a page of that
documentation, an official test vector, or behaviour observed in real conditions - never on another
implementation nor on an assumption.

The plugin targets **Axepta Online 1.0**, the generation with `.aspx` endpoints. Generation 2.0 is a
separate REST API, out of scope.

## What does not belong here

Business logic specific to one shop - payment policies, deposits, checkout rules - stays in the
project using it. This plugin only carries the Axepta protocol and its wiring into Sylius.
