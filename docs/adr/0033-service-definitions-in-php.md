# 0033. Service definitions are written in PHP

- Status: accepted
- Date: 2026-10-20

## Context

Symfony 8 no longer loads service definitions written in XML. The plugin supports Symfony 8
alongside earlier versions, with one code base.

## Decision

The plugin's services are defined in PHP configuration files, which every supported Symfony version
loads. The routes and the Twig hooks configuration stay in YAML.

## Consequences

- Service definitions use the PHP configurator, with every argument and tag written out.
- A shop decorating or substituting a plugin service does so in its own configuration, in whichever
  format it uses.

## Related

- [0024. The back-office fields are rendered through an optional Twig hook](0024-optional-twig-hook.md)
- [0032. One code base for Sylius 2.1 to 2.3: parameter types are widened rather than branched on](0032-one-code-base-sylius-2-1-to-2-3.md)
