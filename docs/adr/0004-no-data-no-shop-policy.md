# 0004. The plugin owns no data and no shop policy

- Status: accepted
- Date: 2026-10-20

## Context

A payment gateway is installed in shops that differ in their checkout rules: deposits, split
payments, which orders may be paid by card, what the customer reads on the bank's page. A plugin
that carried such rules, or stored data of its own to support them, would impose one shop's choices
on every other, and would have its own schema to migrate.

## Decision

The plugin only carries the Axepta protocol and its wiring into Sylius. It declares no entity and no
table: the gateway settings live in the Sylius gateway configuration, and what a payment needs to
remember, such as its transaction identifier, lives in the Sylius payment's details or in its
payment request. It charges the amount and currency of the Sylius payment, describes it with the
Sylius payment description, and references it with the order number.

## Consequences

- The plugin brings no database migration of its own.
- What is paid, and when, stays decided by Sylius and by the shop: a deposit or a split payment is a
  Sylius payment like any other.
- A shop that wants another description, reference or transaction identifier changes it through
  Sylius or through the plugin's substitution points, not by asking the plugin for a setting.
- Business rules specific to one shop stay in that shop's code.

## Related

- [0003. One-off card payments with the bank's automatic capture](0003-one-off-card-payments-automatic-capture.md)
- [0010. The transaction identifier is readable, random and invertible; the merchant reference keeps the end of the order number](0010-bank-identifiers.md)
- [0021. The plugin changes Sylius behaviour only for its own gateway](0021-changes-only-its-own-gateway.md)
- [0029. Gateway configuration keys tolerate their own absence](0029-configuration-keys-tolerate-absence.md)
