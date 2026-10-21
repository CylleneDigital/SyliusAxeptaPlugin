# 0031. An explicit public contract, broken only in a major version

- Status: accepted
- Date: 2026-10-20

## Context

A payment plugin is upgraded on shops that take money. Semantic versioning only protects them if
it names what it covers: without a perimeter, every public class is a promise, and no internal
change can be made in a minor version.

## Decision

The public contract is: the protocol layer, the gateway factory and its `axepta` name, the gateway
configuration keys, the bundle settings (`payment_page_url`, `logger_channel`), the substitution
interfaces, the credentials provider, the gateway configuration form type, the back-office hookable
and the template paths. The classes marked internal may change in a minor version. The plugin
defines no public service tag: the one it declares for its own wiring is internal.

The public contract breaks only in a major version, except to close a security leak, documented as
such in the upgrade notes. Every break is documented with the exact manoeuvre to carry out.

## Consequences

- The factory name and the configuration keys are stored in the shop's database: renaming one
  would require a data migration, so they are as stable as the classes.
- A shop extends the plugin through substitution, configuration, hooks and template overriding,
  never by depending on an internal class.
- The `PaymentRequest` adapter is internal, and additionally follows the experimental status of its
  Sylius foundation.

## Related

- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0024. The back-office fields are rendered through an optional Twig hook](0024-optional-twig-hook.md)
- [0029. Gateway configuration keys tolerate their own absence](0029-configuration-keys-tolerate-absence.md)
- [0034. Every exception the protocol layer throws implements a shared marker and keeps its standard parent](0034-protocol-exceptions-share-a-marker.md)
