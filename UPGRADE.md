# Upgrade guide

This file lists, one major version at a time, what has to change in a project already using the
plugin.

A payment plugin is upgraded in production, on a site that takes money: every compatibility break
must be written here before it is released, together with the exact manoeuvre to carry out.

## From 1.1.x to 1.1.2

Nothing to change in a project that only configures the plugin through the back office and the
bundle configuration.

### Payment page URL

A `payment_page_url` stored in a gateway configuration is now ignored: the payment page URL comes
from the bundle setting `cyllene_digital_sylius_axepta.payment_page_url` only. The back office never
wrote that key; a project that set it by hand in the database must move the value to the bundle
configuration.

### Notify payment provider (PaymentRequest path)

A project that registered its own notify payment provider for Axepta payment methods - to handle
GET notifications of alternative payment means, for instance - must give it a priority above 10:
the plugin's provider now claims every request on an Axepta payment method's notification URL,
GET included, and answers a 404 to what it cannot use.

### Credentials

`AxeptaCredentials` can no longer be serialised: its keys are held in `\SensitiveParameterValue`,
which PHP refuses to serialise. A project that serialised it must keep the gateway configuration
instead and build the credentials through `CredentialsProvider`.

### Logs

Monitoring keyed on the `axepta` log channel may need adjusting.

On both paths:

- a paid notification that can no longer settle its payment - failed or cancelled - is a new
  error;
- a GET notification carrying `Data`, as alternative payment means send it, is a new error.

On the Payum path:

- "Axepta notification tied to the wrong payment" (warning) became "Axepta notification
  designating another payment" (error), and its context keys `expected_transaction_id` and
  `received_transaction_id` became `transaction_id`;
- "Axepta refusal of a superseded attempt: ignored." is a new info message.

On the PaymentRequest path, the notification refusals are now logged on the `axepta` channel:

- a message without an encrypted payload, or not authenticated: warning;
- an authenticated notification matching no payment of the method: error;
- a payment method whose configuration is incomplete: error;
- a capture handled outside an HTTP request, meaning an asynchronous `payment_request` transport:
  error.

## From 1.0 to 1.1

Nothing to change in the project: 1.1 adds Sylius 2.3 and Symfony 8 support and breaks nothing.

The plugin now defines its services in PHP, Symfony 8 no longer reading XML. Service ids, tags and
arguments are unchanged: a project that decorates or overrides them by id keeps working.
