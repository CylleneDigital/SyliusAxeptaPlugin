# Versioning and support

This plugin follows [semantic versioning](https://semver.org). This page states what that promise
covers, which is the only way it means anything: a guarantee that does not name its perimeter is not
a guarantee.

## Public API

A break in any of the following requires a major version.

| | |
|---|---|
| The classes of `src/Axepta/` | The protocol layer, usable on its own, without Sylius or Payum |
| `AxeptaGatewayFactory` and the `axepta` factory name | The name is stored in `sylius_gateway_config.factory_name`; renaming it would require a data migration |
| The configuration keys | `merchant_id`, `hmac_key`, `blowfish_key`, `test_mode`, `language` - stored as such in `sylius_gateway_config.config` |
| The substitution points | `BlowfishEcbFactoryInterface`, `BlowfishEcbInterface`, `TransactionIdGeneratorInterface`, `ReferenceProviderInterface` |
| `CredentialsProvider` | The single entry point both payment paths go through |
| The `axepta_fields` hookable and the template paths | What an integrator hooks onto to replace a rendering |

### Substituting a generator carries an obligation

`TransactionIdGeneratorInterface` declares two methods, and both are part of the contract.
`resolvePaymentIdentifier()` is how the PaymentRequest path finds the notified payment, by primary
key, its notification URL being shared across the payment method.

A generator emitting identifiers its own inverse does not recognise leaves every notification
unattached: the bank retries eight times over ~21 h and then gives up, on orders the customer has
paid for.

## Internals

Marked `@internal`, and changeable in a minor version: the Payum actions, the commands, handlers and
providers of the PaymentRequest path, the renderer and the container extension.

The classes of the `PaymentRequest` adapter additionally carry `@experimental`, mirroring the Sylius
foundation they build on. Their evolution to follow a Sylius contract change is not a break of our
doing.

## Support policy

| Line | What is covered |
|---|---|
| Latest minor of the current major | Bug and security fixes |
| Previous major | Security only, for 12 months after the next major is released |
| Sylius versions | Those of the continuous integration matrix; support ends when Sylius itself drops a version |

A flaw is reported privately, see [SECURITY.md](../SECURITY.md). Do not open it as a public issue.

## Upgrading

Every compatibility break is written in [UPGRADE.md](../UPGRADE.md) before it is released, with the
exact manoeuvre to carry out. A payment plugin is upgraded in production, on a site that takes
money: "read the diff" is not an upgrade guide.
