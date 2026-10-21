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

`make init` already covers it: the `nodejs` service of `compose.override.dist.yml` runs
`yarn install && yarn build` inside `vendor/sylius/test-application`. Rebuilding it alone, without
the Makefile:

```bash
docker run --rm -v "$PWD":/app -w /app/vendor/sylius/test-application \
  -u "$(id -u):$(id -g)" -e HOME=/tmp node:22-alpine sh -c "yarn install && yarn build"
```

`tests/TestApplication/package.json` raises `@symfony/stimulus-bridge` to `^4.0.2`: the test
application's `preinstall` merges it into its own `package.json`. The 3.x loader requires
`webpack/lib/dependencies/LoaderDependency`, gone since webpack 5.112, and the Sylius 2.1 front
build fails on it (`yarn install` runs without a lockfile).

### A file added under `config/services/` is not seen by `cache:clear`

`config/services.php` imports `services/*.php`, and the `GlobResource` keeps the list frozen. You need
`rm -rf var/cache/*`. Symptom: the services of the new file do not exist, without the slightest
error.

### The notify payment provider needs an explicit priority

The test application imports Sylius' Behat services, among which a `DummyNotifyPaymentProvider`
whose `supports()` answers true to everything and which returns **the oldest payment in the
database**. Symptom if the priority (`10`) goes: a 2xx response, a `completed` notification request,
but on the wrong payment - and only the first payment in the database appears to work. A host
provider meant to handle some Axepta notifications likewise needs a priority above `10`
([0021](docs/adr/0021-changes-only-its-own-gateway.md)).

## Test database

Sylius encrypts the gateway configuration (`sylius_payment.encryption.enabled` defaults to true).
**Without an encryption key, every write fails on `Cannot read keyfile`.** The key lives in
`vendor/sylius/test-application/config/encryption/` and therefore disappears on every
`composer install` or `update` that replaces the test application. The gateway configurations
already in a database were encrypted with the lost key: the payment methods grid then answers 500
on a decryption error. Recreate those payment methods, or reload the fixtures.

```bash
APP_ENV=test vendor/bin/console doctrine:schema:update --force --complete -n
APP_ENV=test vendor/bin/console sylius:payment:generate-key
```

## Invariants not to break

Each rule is here in full, with the names the code uses; its reasons are in the decision record it
links ([`docs/adr/`](docs/adr/README.md)). Read the record before changing a rule, and write a new
record rather than editing an accepted one - the records hold no path nor figure, this file does.

- **`BlowfishEcb` stays pure PHP.** `openssl_encrypt('BF-ECB')` returns `false` under OpenSSL 3 -
  Blowfish sits in the legacy provider, disabled by default. Never "simplify" by wiring OpenSSL back
  in. This is cryptographic code validated against legacy OpenSSL `BF-ECB` output and the standard
  Blowfish vector; BNP's five official vectors only cover the HMAC signature
  ([0007](docs/adr/0007-blowfish-pure-php-substitutable.md)).
- **A handled notification answers 2xx** - `204` from PayumBundle on Payum, `200` from the
  decorated `NotifyResponseProviderInterface` on PaymentRequest. A 404 or a 500 triggers 8 BNP
  retries over ~21 h 36. Hence the catching of exceptions in `NotifyAction` and
  `NotifyAxeptaPaymentRequestHandler`; a double notification is the nominal case. Deliberate
  exception: `AxeptaNotifyPaymentProvider` answers 404 to what it cannot tie to a payment - see
  [`docs/protocol.md`](docs/protocol.md)
  ([0012](docs/adr/0012-notification-answers-2xx-deliberate-404.md),
  [0013](docs/adr/0013-double-notification-no-op.md)).
- **The payload goes out in ISO-8859-1**, and `Len` counts the converted bytes. Handled badly, this
  only breaks a fraction of orders - those whose description carries an accent
  ([0008](docs/adr/0008-unrepresentable-characters-replaced.md)).
- **Both payment paths are in scope** from v1. Sylius 2 routes according to
  `GatewayConfig::usePayum`. The classes of the `PaymentRequest` adapter carry `@experimental`, like
  the matching Sylius foundation ([0019](docs/adr/0019-both-payment-paths-shared-core.md)).
- **The key fields keep `always_empty` at its default, `true`.** The `PRE_SUBMIT` listener of
  `AxeptaGatewayConfigurationType` puts the stored keys back when they come back empty - that is what
  lets a plain save pass their `NotBlank` without retyping them. With `false`, a submission failing
  validation would render those stored keys in the page
  ([0026](docs/adr/0026-keys-never-redisplayed-one-pair.md)).
- **Service definitions stay in PHP.** Symfony 8 no longer loads XML service definitions, and Behat 4
  (the one Symfony 8 requires) neither YAML configuration nor step annotations: services in
  `config/services/*.php`, Behat in `behat.dist.php`, steps as `#[Given]` attributes. Routes and twig
  hooks stay in YAML (`config/routes/`, `config/twig_hooks/`)
  ([0033](docs/adr/0033-service-definitions-in-php.md)).
- **Sylius 2.1 to 2.3 with one code base.** Where Sylius changed a signature, widen the parameter
  rather than branch on the version - `AxeptaHttpResponseProvider` takes `object $request` because
  2.1 / 2.2 pass a `RequestConfiguration` and 2.3 a `Request`
  ([0032](docs/adr/0032-one-code-base-sylius-2-1-to-2-3.md)).
- **New configuration keys must tolerate their own absence**: a gateway configuration written by an
  earlier version does not carry them ([0029](docs/adr/0029-configuration-keys-tolerate-absence.md)).
- The public contract only breaks in a major version, except to close a security leak documented
  as such in `UPGRADE.md`: the classes of `src/Axepta/`, `AxeptaGatewayFactory` and its `axepta`
  name, the gateway configuration keys, the bundle settings `payment_page_url` and `logger_channel`,
  the substitution interfaces, `CredentialsProvider`, `AxeptaGatewayConfigurationType`, the
  `axepta_fields` hookable and the template paths. The classes marked `@internal` may change in a
  minor version, and the plugin defines no public service tag
  ([0031](docs/adr/0031-explicit-public-contract.md)).

## The protocol is not written from memory

`PaymentPageRequestBuilder` and `NotificationVerifier` are written **from BNP's public
specification** (<https://docs.axepta.bnpparibas>). Any protocol change must rest on a page of that
documentation, an official test vector, or behaviour observed in real conditions - never on another
implementation nor on an assumption ([0005](docs/adr/0005-protocol-rules-rest-on-evidence.md)).

The plugin targets **Axepta Online 1.0**, the generation with `.aspx` endpoints. Generation 2.0 is a
separate REST API, out of scope ([0001](docs/adr/0001-target-axepta-online-1-0.md)).

## What does not belong here

Business logic specific to one shop - payment policies, deposits, checkout rules - stays in the
project using it. This plugin only carries the Axepta protocol and its wiring into Sylius
([0004](docs/adr/0004-no-data-no-shop-policy.md)).
