# Security policy

This plugin handles signing keys and verifies the authenticity of payment notifications. A flaw here
has a direct financial impact, so please report it privately.

## Reporting a vulnerability

**Do not open a public issue.** Use either of the two private channels:

- [GitHub security advisory](https://github.com/CylleneDigital/SyliusAxeptaPlugin/security/advisories/new)
  ("Report a vulnerability");
- email to sylius@groupe-cyllene.com

Please include the plugin version, the Sylius and PHP versions, the payment path involved (Payum or
PaymentRequest), and the steps to reproduce. **Redact every key, every MAC and every merchant
identifier** from the logs you attach.

## Response time

First response within **5 working days**. We keep you posted on the analysis, then on the fix and
its release date.

## Supported versions

| Version | Support |
|---|---|
| `1.x` | Bug and security fixes |

The full policy, including what happens to a major version once the next one is out, is in
[`docs/versioning.md`](docs/versioning.md).

## Disclosure

Coordinated disclosure: the fix is released first, then the advisory. We are happy to credit the
reporter, unless they ask otherwise.
