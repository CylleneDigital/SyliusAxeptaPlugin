# Upgrade guide

This file lists, one major version at a time, what has to change in a project already using the
plugin.

A payment plugin is upgraded in production, on a site that takes money: every compatibility break
must be written here before it is released, together with the exact manoeuvre to carry out.

## From 1.1 to 1.1.2

Nothing to change in a project that only configures the plugin.

A project that registered its own notify payment provider for Axepta payment methods - to handle
GET notifications of alternative payment means, for instance - must give it a priority above 10:
the plugin's provider now claims every request on an Axepta payment method's notification URL,
GET included, and answers a 404 to what it cannot use.

## From 1.0 to 1.1

Nothing to change in the project: 1.1 adds Sylius 2.3 and Symfony 8 support and breaks nothing.

The plugin now defines its services in PHP, Symfony 8 no longer reading XML. Service ids, tags and
arguments are unchanged: a project that decorates or overrides them by id keeps working.
