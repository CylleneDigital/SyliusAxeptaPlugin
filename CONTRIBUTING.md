# Contributing

Thanks for your interest in this plugin. Issues and pull requests are welcome.

**A security flaw is not reported through an issue** - see [SECURITY.md](SECURITY.md).

## Setting up the development environment

The plugin is tested against a throwaway Sylius application (`sylius/test-application`), brought up
by Docker.

```bash
make init            # compose.override.yml, composer install, front-end build, containers
make database-init   # database and migrations
make load-fixtures   # Sylius sample data (optional)
```

Without Docker, with a local PHP and a reachable MySQL database:

```bash
composer install
composer run test-app-init
```

## What has to pass before a pull request

```bash
vendor/bin/phpunit --testsuite=unit       # unit tests
vendor/bin/phpunit --testsuite=non-unit   # integration and functional
vendor/bin/phpstan analyse -c phpstan.neon
vendor/bin/ecs check
vendor/bin/behat --strict
```

The Docker equivalents exist: `make phpunit`, `make phpstan`, `make ecs`, `make behat`.

`vendor/bin/ecs check --fix` fixes the style automatically. The standard is
[`sylius-labs/coding-standard`](https://github.com/Sylius-Labs/CodingStandard), the one used by
Sylius plugins, do not add personal rules to it.

## Conventions

- **PHPStan at level `max`** on `src/`. A pull request lowering the level or adding an
  `ignoreErrors` entry must explain why in its description.
- **Tests are mandatory** for any bug fix: the test must fail before the fix.
- **`UPGRADE.md`** updated if the pull request breaks compatibility. The public contract - protocol
  classes, substitutable interfaces, hookable names, service tags, gateway configuration keys - only
  breaks in a major version.
- Never a real key, MAC or merchant identifier in the code, the tests, the fixtures or an issue.
  Tests use obviously fake values.
- The codebase is written in **English**, comments and commit messages included.

## What does not belong here

Business logic specific to one shop - payment policies, deposits, checkout rules - stays in the
project using it. This plugin only carries the Axepta protocol and its wiring into Sylius.
