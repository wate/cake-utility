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

### Audit Log

Record and manage audit tRails with automatic model tracking and manual controller logging.

- Behavior-based auto recording (create/update/delete)
- Component-based explicit recording (login/logout/etc.)
- Automatic IP/User-Agent collection
- Sanitize callback for PII masking
- Retention-based auto-purge with CSV archive
- CLI purge command

[Read more →](docs/audit_log.md)

### ActionModal

Confirmation dialog component using Bootstrap 4/AdminLTE 3 modals.

- Helper: Trigger button output with data-* attributes
- Element: Modal markup with automatic CSRF token embedding
- JS: Pure JS + HTMX modal control
- i18n: English source + Japanese .po included

[Read more →](docs/action_modal.md)
