# Installation

## Requirements

| | |
|---|---|
| PHP | `^8.2`, with `hash` and `mbstring` |
| Sylius | `^2.1` |
| An Axepta contract | Merchant identifier and keys, supplied by BNP Paribas |

No exotic extension: the Blowfish encryption is pure PHP, precisely so that nothing is required of
your image. See [the note on OpenSSL](#why-blowfish-in-pure-php).

## 1. Install the package

```bash
composer require cyllene-digital/sylius-axepta-plugin
```

### If the Flex recipe was applied, steps 2 and 3 are already done

The package ships a Symfony Flex recipe. When Composer applies it, it registers the bundle in
`config/bundles.php` and writes `config/routes/cyllene_digital_sylius_axepta.yaml`, which imports the
plugin's shop routes.

The recipe lives in the **contrib** repository: Composer only applies it if the project allows those,
either by answering yes to its prompt or through `extra.symfony.allow-contrib`. It is also skipped
altogether by `--no-scripts`.

So check rather than assume - these are the two files whose absence breaks payments:

```bash
grep Axepta config/bundles.php
cat config/routes/cyllene_digital_sylius_axepta.yaml
```

Both there: **skip to step 4**. Either one missing: carry out the matching step below by hand. Steps
4 to 7 are yours in every case, the recipe only prints a reminder of them.

## 2. Register the bundle

```php
// config/bundles.php
return [
    // ...
    CylleneDigital\SyliusAxeptaPlugin\CylleneDigitalSyliusAxeptaPlugin::class => ['all' => true],
];
```

The plugin has **neither entity nor migration**: nothing to do on the database side.

## 3. Import the routes

⚠️ **This step is not optional.** The plugin exposes the page the customer returns to from the
bank's payment page. Without it the return URL cannot be built and **the payment fails before it
even leaves**.

The recipe writes the same import under its own file name,
`config/routes/cyllene_digital_sylius_axepta.yaml`. One import is enough - do not declare it twice.

```yaml
# config/routes.yaml
cyllene_digital_sylius_axepta_shop:
    resource: "@CylleneDigitalSyliusAxeptaPlugin/config/routes/shop.yaml"
```

⚠️ **Do not put this import behind the shop's `/{_locale}` prefix.** The route is built to sit
outside it: it carries no locale, and the controller reads the one on the order to send the customer
to a thank-you page in their own language.

Under a prefix, `_locale` becomes a mandatory parameter of the route, while the return URL is
generated without it. Generating that URL then only works from a context where the locale has been
resolved from a request, and a payment captured by a Messenger worker has no request. The prefixed
variant is also the one nothing exercises: the test suite and every real payment ran on this import.

## 4. Generate the payment encryption key

Sylius encrypts the gateway configuration in the database. If you have never done it:

```bash
bin/console sylius:payment:generate-key
```

⚠️ **Back this key up separately from the database.** A backup containing both protects nothing, and
losing the key makes the configuration of your payment methods unrecoverable.

## 5. Configure the trusted proxies

**This is the step people forget, and it breaks payments with no error message.**

The return and notification URLs handed to the bank are generated absolutely, from the host of the
current request. Behind a reverse proxy, a load balancer or a development tunnel, Symfony sees the
internal host - and you hand BNP a URL it cannot reach. The notification then never arrives, and the
order stays unpaid although the customer has paid.

```yaml
# config/packages/framework.yaml
framework:
    trusted_proxies: 'private_ranges'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
```

`x-forwarded-host` is the one not to forget: Symfony does **not** trust it by default, and yet it is
the only header carrying the public domain. Without it the host stays wrong even with the proxies
declared.

Replace `private_ranges` with the exact list of your proxies if you know them - trusting every
private range assumes nothing else can reach the application directly.

> ⚠️ If you would rather drive the value from an environment variable, **do not name it
> `TRUSTED_PROXIES`** when your `public/index.php` comes from the Sylius skeleton: it reads that
> variable and calls `Request::HEADER_X_FORWARDED_ALL`, a constant removed in Symfony 6. Setting it
> replaces the shop with a fatal error.

Then check that the generated URLs really are public:

```bash
bin/console debug:router sylius_payment_method_notify
```

## 6. Create the payment method

**Configuration → Payment methods → Create**, then "Axepta - BNP Paribas".

The rest is in [`configuration.md`](configuration.md).

## 7. The infrastructure points

The plugin cannot take care of these, and nobody handles them unless they are named. Two are the
steps above; the other three are yours to arrange.

| Point | What to do |
|---|---|
| **Trusted proxies** | Step 5. Without them, **you hand BNP unreachable URLs** and the notification never arrives |
| **Encryption key** | Step 4. Back it up **separately from the database**: together they protect nothing; lost, the gateway configuration is unrecoverable |
| **HTTPS** | Mandatory on every URL, return as well as notification. BNP only accepts port 443 |
| **IP restriction** | BNP publishes its outbound ranges. Filter at the reverse proxy, not in the application - the addresses change, and an over-zealous filter breaks payments |
| **Rate limiting** | The notification URL is public and answers before any authentication. `symfony/rate-limiter` or a WAF |

On the last point, the plugin already caps the size of the `Data` field before decrypting it: the
signature can only be verified after decryption, so anyone can otherwise put the decipherer to work
without holding a key. That cap protects the CPU, not the URL - the rate limiting is still yours.

## Checking the installation

```bash
bin/console lint:container
bin/console debug:container --tag=payum.gateway_factory_builder | grep axepta
bin/console debug:router | grep axepta
```

The `axepta` factory must show up - otherwise the bundle is not loaded. The
`cyllene_digital_sylius_axepta_shop_return` route must show up too, as `GET|POST` - otherwise step 3
was skipped, and your payments will fail at redirect time.

## Why Blowfish in pure PHP

The Axepta protocol encrypts its data with Blowfish-ECB. Since OpenSSL 3, that algorithm has been
moved to the *legacy provider*, disabled by default: `openssl_encrypt($data, 'BF-ECB', ...)` returns
`false` on any recent PHP image.

The plugin therefore ships its own implementation, checked against the reference vectors - including
the standard Blowfish vector. You have nothing to enable, and no obsolete algorithm is re-enabled
globally in your image.

If you have a native implementation or an HSM, substitute `BlowfishEcbFactoryInterface`:

```yaml
services:
    App\Payment\NativeBlowfishFactory: ~

    CylleneDigital\SyliusAxeptaPlugin\Axepta\Crypto\BlowfishEcbFactoryInterface:
        alias: App\Payment\NativeBlowfishFactory
```
