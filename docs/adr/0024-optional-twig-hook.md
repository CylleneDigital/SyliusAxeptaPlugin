# 0024. The back-office fields are rendered through an optional Twig hook

- Status: accepted
- Date: 2026-10-20

## Context

The Sylius back office renders the gateway configuration of a payment method through Twig hooks,
one section per gateway factory. Without a hookable on the `axepta` section, the plugin's fields
would not show. Requiring the Twig hooks package outright would impose it on an application that
does not have it, although the payments themselves do not need it.

## Decision

The fields are rendered by a hookable named `axepta_fields`, on the creation and update forms of a
payment method. The plugin registers it only when Sylius' Twig hooks are present in the
application.

## Consequences

- Without Twig hooks, the plugin still takes payments; only its configuration fields do not show.
- A shop disables or replaces the rendering through its own hook configuration, by that name.
- The hookable name is part of the public contract: renaming it would silently undo those
  overrides.

## Related

- [0026. Keys are never redisplayed, survive a blank save, and one pair is active at a time](0026-keys-never-redisplayed-one-pair.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
