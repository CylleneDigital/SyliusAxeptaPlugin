# 0032. One code base for Sylius 2.1 to 2.3: parameter types are widened rather than branched on

- Status: accepted
- Date: 2026-10-20

## Context

The plugin supports several Sylius minor versions, and the Symfony versions each of them runs on.
Between them, Sylius changed some signatures the plugin implements, and Symfony some of the types
its APIs declare. Branching on the installed version, or keeping one line of the plugin per Sylius
version, would multiply the code paths of a payment plugin.

## Decision

One code base serves every supported version. Where a signature changed, the plugin widens its
parameter type so that one implementation satisfies every variant of the interface: the HTTP
response provider takes any object as its request, since Sylius passes a request configuration in
some versions and a request in others, and reads neither. Where a Symfony API is typed differently
across versions, the code goes through the form both accept.

## Consequences

- No version check in the code: a shop upgrading Sylius within the supported range keeps the same
  plugin.
- A widened parameter accepts more than either Sylius version passes; the plugin must not rely on
  its type.

## Related

- [0019. Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md)
- [0031. An explicit public contract, broken only in a major version](0031-explicit-public-contract.md)
- [0033. Service definitions are written in PHP](0033-service-definitions-in-php.md)
