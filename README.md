CakeUtility plugin for CakePHP
==============================

A collection of utility components for CakePHP applications.

Installation
-------------------------

You can install this plugin into your CakePHP application using [composer](https://getcomposer.org).

The recommended way to install composer packages is:

```
composer require wate/cake-utility
```

Features
-------------------------

### Locale Middleware

Automatically detect and set application locale based on:

- URL parameters (`?lang=en_US`)
- Stored cookie preference
- Browser Accept-Language header (RFC 9110 compliant)

[Read more →](docs/locale_middleware.md)

### YAML Loader

Convert YAML test/seed data to database-compatible format with support for:

- Record references (`ref:`)
- Dynamic date/time (`@now`, `@today`)
- Upsert operations (`_keys`)

[Read more →](docs/yaml_loader.md)

### Scenario Loader

Load structured test scenarios from YAML files.

[Read more →](docs/scenario_loader.md)
