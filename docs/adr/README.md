# Architecture decision records

One file per decision: its context, what was decided, what follows from it. A decision is not
edited once accepted: a new record supersedes it, and the old one says so in its status.

A record holds the decision and its reasons, which do not move with the code. What does - class
lists, configuration details, observed values - is in the guides, which follow the code:
[protocol](../protocol.md), [configuration](../configuration.md),
[installation](../installation.md), [Payum or PaymentRequest](../payum-vs-payment-request.md). A
record that quoted them would be wrong at the next refactoring, and could not be corrected. A
record explains a choice, it promises nothing: only [versioning and support](../versioning.md)
says what is stable.

A new record takes the next number, the template of the others (Status, Date, Context, Decision,
Consequences, Related) and a line in the table below.

| # | Decision |
|---|---|
| 0001 | [The plugin targets Axepta Online 1.0 only](0001-target-axepta-online-1-0.md) |
| 0002 | [The plugin posts to the card form, not the payment page offering alternative payment means](0002-card-form-not-multi-means-page.md) |
| 0003 | [One-off card payments with the bank's automatic capture](0003-one-off-card-payments-automatic-capture.md) |
| 0004 | [The plugin owns no data and no shop policy](0004-no-data-no-shop-policy.md) |
| 0005 | [Every protocol rule rests on the bank's documentation, an official vector or an observed behaviour](0005-protocol-rules-rest-on-evidence.md) |
| 0006 | [The protocol layer depends on neither Sylius nor Payum, and its services take the credentials per call](0006-protocol-layer-independent-credentials-per-call.md) |
| 0007 | [Blowfish-ECB is pure PHP, substitutable through a factory](0007-blowfish-pure-php-substitutable.md) |
| 0008 | [Characters ISO-8859-1 cannot hold are replaced, not transliterated](0008-unrepresentable-characters-replaced.md) |
| 0009 | [Payload separators are neutralised in the description and refused in URLs](0009-payload-separators-neutralised.md) |
| 0010 | [The transaction identifier is readable, random and invertible; the merchant reference keeps the end of the order number](0010-bank-identifiers.md) |
| 0011 | [Only the server-to-server notification changes a payment, and the bank is never queried](0011-notification-only-source-of-truth.md) |
| 0012 | [A handled notification answers 2xx; on the PaymentRequest path, what cannot be tied to a payment gets a deliberate 404](0012-notification-answers-2xx-deliberate-404.md) |
| 0013 | [A double notification is a no-op: transitions are guarded, no lock is taken](0013-double-notification-no-op.md) |
| 0014 | [An unknown status changes nothing](0014-unknown-status-changes-nothing.md) |
| 0015 | [A notification is authenticated on its encrypted payload alone, against the configured merchant identifier](0015-authenticated-on-encrypted-payload-configured-merchant.md) |
| 0016 | [Any attempt can pay a payment, only the latest can refuse it](0016-any-attempt-pays-latest-refuses.md) |
| 0017 | [The work done before the signature is checked is bounded](0017-bounded-work-before-signature.md) |
| 0018 | [The plugin logs to its own channel, at a level set by what is at stake](0018-own-log-channel-level-by-stake.md) |
| 0019 | [Both Sylius payment paths, on one shared core](0019-both-payment-paths-shared-core.md) |
| 0020 | [The PaymentRequest path is notified on the payment method's URL](0020-payment-request-notified-on-method-url.md) |
| 0021 | [The plugin changes Sylius behaviour only for its own gateway](0021-changes-only-its-own-gateway.md) |
| 0022 | [The customer returns through the plugin's own signed route, which decides nothing](0022-own-signed-return-route.md) |
| 0023 | [A standalone transition page posts to the bank and works without JavaScript](0023-standalone-transition-page.md) |
| 0024 | [The back-office fields are rendered through an optional Twig hook](0024-optional-twig-hook.md) |
| 0025 | [The PaymentRequest capture runs inside the customer's request](0025-capture-inside-customer-request.md) |
| 0026 | [Keys are never redisplayed, survive a blank save, and one pair is active at a time](0026-keys-never-redisplayed-one-pair.md) |
| 0027 | [The payment page URL comes from the bundle configuration only; the merchant identifier decides the environment](0027-endpoint-from-bundle-configuration.md) |
| 0028 | [Test mode only forces the demonstration description, and the page language follows the order unless forced](0028-test-mode-and-language.md) |
| 0029 | [Gateway configuration keys tolerate their own absence](0029-configuration-keys-tolerate-absence.md) |
| 0030 | [Secrets stay out of logs, dumps, traces and serialisation](0030-secrets-out-of-logs-dumps-traces.md) |
| 0031 | [An explicit public contract, broken only in a major version](0031-explicit-public-contract.md) |
| 0032 | [One code base for Sylius 2.1 to 2.3: parameter types are widened rather than branched on](0032-one-code-base-sylius-2-1-to-2-3.md) |
| 0033 | [Service definitions are written in PHP](0033-service-definitions-in-php.md) |
| 0034 | [Every exception the protocol layer throws implements a shared marker and keeps its standard parent](0034-protocol-exceptions-share-a-marker.md) |
