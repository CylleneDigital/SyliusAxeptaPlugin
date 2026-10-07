# Upgrade guide

This file lists, one major version at a time, what has to change in a project already using the
plugin.

A payment plugin is upgraded in production, on a site that takes money: every compatibility break
must be written here before it is released, together with the exact manoeuvre to carry out.

## From 1.0 to 1.1

Nothing to change in the project: 1.1 adds Sylius 2.3 and Symfony 8 support and breaks nothing.

The plugin now defines its services in PHP, Symfony 8 no longer reading XML. Service ids, tags and
arguments are unchanged: a project that decorates or overrides them by id keeps working.
